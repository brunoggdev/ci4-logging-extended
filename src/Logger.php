<?php

declare(strict_types=1);

namespace Brunoggdev\LoggingExtended;

use Brunoggdev\LoggingExtended\Config\LoggingExtended;
use Brunoggdev\LoggingExtended\LogAlert;
use CodeIgniter\Cache\CacheFactory;
use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\Log\Logger as CI4Logger;
use Config\Cache as CacheConfig;
use Throwable;

/**
 * Extended Logger for CodeIgniter 4
 *
 * Drop-in replacement for CI4's native Logger that properly serializes
 * context arrays into the log message, even when no {placeholders} are used.
 *
 * Any context key that was NOT consumed by a {placeholder} in the message
 * gets appended as key=value pairs after a pipe separator:
 *
 *   logger()->error('Job failed', ['job' => 'SendEmail', 'attempt' => 3]);
 *   // ERROR - 2026-03-20 14:32:01 --> Job failed | job=SendEmail attempt=3
 */
class Logger extends CI4Logger
{
    /** Isolated file cache for alert throttling — shared across all Logger instances. */
    private static ?CacheInterface $alertCache = null;

    /**
     * Context keys we add in buildExceptionContext() that must NOT appear in LogAlert::$context.
     * 'message' and 'location' are promoted to dedicated LogAlert props.
     * 'class' and 'trace' are redundant via $alert->exception.
     */
    private const ALERT_STRIP_KEYS = ['class', 'message', 'location', 'trace'];

    /**
     * Raw Throwable threaded from exception() to handleAlerts() without touching the
     * PSR-3 context array. Cleared at the top of handleAlerts() before any work begins.
     */
    private ?Throwable $pendingException = null;

    /**
     * Names of context keys that hold our configured captures (request, user, session, extra).
     * Key names only — values stay in the $context array to avoid duplication.
     * Set by buildExceptionContext(); empty for plain logger calls and CI4's native handler path.
     *
     * @var string[]
     */
    private array $pendingCaptureKeys = [];

    /**
     * Logs a caught Throwable with rich context: location, request, user, trace.
     *
     * Behaviour is driven by Config\LoggingExtended. Override this method in a
     * subclass to add external integrations (Sentry, Bugsnag, etc.) while
     * keeping file logging via parent::exception().
     *
     * @param string|null $message Optional message when the call site has more context than
     *                             the exception message itself (e.g. 'Failed to process order').
     *                             Defaults to the exception's own message.
     */
    public function exception(Throwable $e, string $level = 'error', ?string $message = null): void
    {
        /** @var LoggingExtended $config */
        $config = config('LoggingExtended');

        $context = $this->buildExceptionContext($e, $config);

        if ($message !== null) {
            // Custom message is the log line — don't leak the exception's own message into context
            unset($context['message']);
        }

        $this->pendingException = $e;
        $this->log($level, '[{class}] ' . ($message ?? '{message}'), $context);
    }

    /**
     * Builds the flat context array for an exception log entry.
     * Separated so subclasses can call it when extending exception().
     *
     * We serialize exception data into context keys so placeholders like {class} and {message}
     * work in the log template and the file format stays consistent with CI4's own handler.
     * The raw Throwable is threaded separately via $pendingException.
     * Capture keys (request, user, session, extra) are tracked in $pendingCaptureKeys so
     * handleAlerts() can split them into LogAlert::$captures without duplicating their values.
     */
    protected function buildExceptionContext(Throwable $e, LoggingExtended $config): array
    {
        $req = $config->exception['request'];
        $cap = $config->exception['captures'];

        $this->pendingCaptureKeys = [];

        $context = [
            'class'    => $e::class,
            'message'  => $e->getMessage(),
            'location' => $this->resolveExceptionLocation($e),
        ];

        if ($req['enabled'] && ! is_cli()) {
            $request     = service('request');
            $requestData = [
                'method' => $request->getMethod(),
                'url'    => current_url(),
            ];

            if ($req['params']) {
                $params = [];
                $get    = $request->getGet() ?: [];
                $post   = $request->getPost() ?: [];
                $json   = (array) ($request->getJSON(true) ?: []);

                if ($get !== []) {
                    $params['query'] = $this->redactParams($get, $req['redact']);
                }

                if ($post !== []) {
                    $params['body'] = $this->redactParams($post, $req['redact']);
                } elseif ($json !== []) {
                    $params['body'] = $this->redactParams($json, $req['redact']);
                }

                if ($params !== []) {
                    $requestData['params'] = $params;
                }
            }

            if ($req['headers'] !== false) {
                $allowList = is_array($req['headers']) ? $req['headers'] : [];
                $headers   = [];

                foreach ($request->getHeaders() as $name => $header) {
                    if ($allowList !== [] && ! in_array($name, $allowList, true)) {
                        continue;
                    }

                    $headers[$name] = is_array($header)
                        ? implode(', ', array_map(fn ($h) => $h->getValueLine(), $header))
                        : $header->getValueLine();
                }

                if ($headers !== []) {
                    $requestData['headers'] = $this->redactParams($headers, $req['redact']);
                }
            }

            $context['request']         = $requestData;
            $this->pendingCaptureKeys[] = 'request';
        }

        if (is_callable($cap['user'])) {
            try {
                $user = ($cap['user'])();
                if ($user !== null) {
                    $context['user']            = $user;
                    $this->pendingCaptureKeys[] = 'user';
                }
            } catch (Throwable) {
                // Resolver threw — skip rather than crashing the logger itself
            }
        }

        if ($cap['session'] === true && ! is_cli()) {
            try {
                $session = session()->get();
                $context['session']         = $session;
                $this->pendingCaptureKeys[] = 'session';
            } catch (Throwable) {
                // Session unavailable — skip
            }
        } elseif (is_callable($cap['session'])) {
            try {
                $session = ($cap['session'])();
                if ($session !== null) {
                    $context['session']         = $session;
                    $this->pendingCaptureKeys[] = 'session';
                }
            } catch (Throwable) {
                // Resolver threw — skip rather than crashing the logger itself
            }
        }

        foreach ($cap['extra'] as $key => $resolver) {
            try {
                $value = $resolver();
                if ($value !== null) {
                    $context[$key]              = $value;
                    $this->pendingCaptureKeys[] = $key;
                }
            } catch (Throwable) {
                // Resolver threw — skip rather than crashing the logger itself
            }
        }

        if ($config->exception['trace']) {
            // trace goes to the log file only; handlers access it via $alert->exception->getTraceAsString()
            $context['trace'] = $e->getTraceAsString();
        }

        return $context;
    }

    /**
     * Recursively redacts sensitive keys from a params array.
     * Matching is case-insensitive and applies to nested arrays (e.g. JSON bodies).
     *
     * Protected so subclasses that override buildExceptionContext() can reuse this.
     */
    protected function redactParams(array $params, array $redactedKeys): array
    {
        $lower = array_map('strtolower', $redactedKeys);

        array_walk_recursive($params, static function (mixed &$val, string|int $key) use ($lower): void {
            if (in_array(strtolower((string) $key), $lower, true)) {
                $val = '[REDACTED]';
            }
        });

        return $params;
    }

    /**
     * Overrides CI4's log() to dispatch alert handlers after writing to file.
     *
     * Three call paths land here:
     *  - Our exception(): $pendingException and $pendingCaptureKeys are pre-loaded.
     *  - CI4's native exception handler: Throwable arrives via $context['exception'] (PSR-3).
     *  - Plain logger calls: no exception, no captures — context passes through as-is.
     *
     * @param string $level
     * @param string $message
     */
    public function log($level, $message, array $context = []): void
    {
        parent::log($level, $message, $context);
        $this->handleAlerts($level, $message, $context);
    }

    /**
     * Builds and dispatches a LogAlert when the level and handler config warrant it.
     *
     * Pending props are read and cleared first so any re-entrant log() call during
     * handler execution starts with a clean slate. A broken handler never takes down
     * the logger — all calls are wrapped in try/catch. A handler returning false opts
     * out of throttling for that call.
     */
    private function handleAlerts(string $level, string $message, array $context): void
    {
        $captureKeys = $this->pendingCaptureKeys;
        $exception   = $this->pendingException ?? $context['exception'] ?? null;

        $this->pendingCaptureKeys = [];
        $this->pendingException   = null;

        /** @var LoggingExtended $config */
        $alerts = config('LoggingExtended')->exception['alerts'];

        if ($alerts['handlers'] === [] || $alerts['levels'] === []) {
            return;
        }

        $level = strtolower($level);

        if (! in_array($level, $alerts['levels'], true)) {
            return;
        }

        $throttle = (int) $alerts['throttle'];
        $cacheKey = $throttle > 0 ? 'lv_alert_' . md5($level . $message) : null;

        if ($cacheKey !== null && $this->alertCache()->get($cacheKey) !== null) {
            return;
        }

        // For our exception() path location is pre-computed; for CI4's native handler path
        // the Throwable is in $context['exception'] and we resolve it here.
        $location = $context['location'] ?? null;
        if ($location === null && $exception !== null) {
            $location = $this->resolveExceptionLocation($exception);
        }

        // For exception() calls $context['message'] holds the clean exception message;
        // for plain calls we interpolate the template.
        $alertMessage = $context['message'] ?? parent::interpolate($message, $context);

        $captures = array_intersect_key($context, array_flip($captureKeys));

        // $context receives everything we didn't put there: CI4 native keys (exception,
        // exFile, exLine, routeInfo…) and user-provided pairs from plain logger calls.
        $alertContext = array_diff_key(
            $context,
            array_flip([...self::ALERT_STRIP_KEYS, ...$captureKeys]),
        );

        $alert = new LogAlert(
            level:     $level,
            message:   $alertMessage,
            captures:  $captures,
            context:   $alertContext,
            timestamp: \CodeIgniter\I18n\Time::now(),
            exception: $exception,
            location:  $location,
        );

        $delivered = false;

        foreach ($alerts['handlers'] as $handler) {
            $result = null;
            try {
                if (is_callable($handler)) {
                    $result = $handler($alert);
                } elseif (is_string($handler)) {
                    $instance = new $handler();
                    $result   = is_callable($instance) ? $instance($alert) : $instance->handle($alert);
                }

                if ($result !== false) {
                    $delivered = true;
                }
            } catch (Throwable) {
                // A broken handler must never take down the logger itself
            }
        }

        if ($cacheKey !== null && $delivered) {
            $this->alertCache()->save($cacheKey, true, $throttle);
        }
    }

    /**
     * Returns the location where the exception was thrown.
     * For the common case this is $e->getFile():$e->getLine() directly.
     * For factory/named-constructor patterns, walks the trace to find the first call site
     * outside the exception class file — handles inherited named constructors too.
     */
    private function resolveExceptionLocation(Throwable $e): string
    {
        foreach ($e->getTrace() as $frame) {
            if (
                isset($frame['file'], $frame['class'])
                && is_a($e, $frame['class'])
                && $frame['file'] !== $e->getFile()
            ) {
                return $frame['file'] . ':' . ($frame['line'] ?? 0);
            }
        }

        return $e->getFile() . ':' . $e->getLine();
    }

    /** Returns the isolated file cache used for alert throttling. */
    private function alertCache(): CacheInterface
    {
        if (self::$alertCache === null) {
            $storePath = WRITEPATH . 'cache/log_alerts/';

            if (! is_dir($storePath)) {
                mkdir($storePath, 0755, true);
            }

            $config          = new CacheConfig();
            $config->handler = 'file';
            $config->file    = ['storePath' => $storePath, 'mode' => 0640];

            self::$alertCache = CacheFactory::getHandler($config);
        }

        return self::$alertCache;
    }

    /**
     * Overrides CI4's interpolate() to also append any context keys
     * that were not consumed by placeholders in the message.
     *
     * @param string $message
     *
     * @return string
     */
    protected function interpolate($message, array $context = [])
    {
        $original = (string) $message;
        $message  = parent::interpolate($message, $context);

        if (empty($context)) {
            return $message;
        }

        $extras = [];

        foreach ($context as $key => $val) {
            if ($key === 'exception') {
                continue;
            }

            if (! str_contains($original, '{' . $key . '}')) {
                $extras[$key] = $val;
            }
        }

        if (empty($extras)) {
            return $message;
        }

        $parts = [];
        $trace = null;

        foreach ($extras as $key => $val) {
            // Trace goes on its own lines, not inline
            if ($key === 'trace' && is_string($val)) {
                $trace = $val;
                continue;
            }

            if (null === $val) {
                $parts[] = $key . '=null';
            } elseif (is_bool($val)) {
                $parts[] = $key . '=' . ($val ? 'true' : 'false');
            } elseif (is_scalar($val)) {
                $parts[] = str_contains((string) $val, ' ')
                    ? $key . '="' . $val . '"'
                    : $key . '=' . $val;
            } else {
                $parts[] = $key . '=' . json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        $result = empty($parts) ? $message : $message . ' | ' . implode(' ', $parts);

        if ($trace !== null) {
            $result .= PHP_EOL . $trace;
        }

        return $result;
    }
}
