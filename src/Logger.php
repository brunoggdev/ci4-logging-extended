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
            // Custom message is the log line — don't leak the exception's message into context
            unset($context['message']);
        }

        $this->log($level, '[{class}] ' . ($message ?? '{message}'), $context);
    }

    /**
     * Builds the full context array for an exception log entry.
     * Separated so subclasses can call it when extending exception().
     */
    protected function buildExceptionContext(Throwable $e, LoggingExtended $config): array
    {
        $req = $config->exception['request'];
        $ctx = $config->exception['context'];

        $context = [
            'class'    => $e::class,
            'message'  => $e->getMessage(),
            'location' => $e->getFile() . ':' . $e->getLine(),
        ];

        if ($req['enabled'] && ! is_cli()) {
            $request            = service('request');
            $context['request'] = [
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
                    $context['params'] = $params;
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
                    $context['headers'] = $this->redactParams($headers, $req['redact']);
                }
            }
        }

        if (is_callable($ctx['user'])) {
            try {
                $user = ($ctx['user'])();
                if ($user !== null) {
                    $context['user'] = $user;
                }
            } catch (Throwable) {
                // Resolver threw — skip rather than crashing the logger itself
            }
        }

        if ($ctx['session'] === true && ! is_cli()) {
            // Guard: session() is not available in CLI contexts
            try {
                $context['session'] = session()->get();
            } catch (Throwable) {
                // Session unavailable — skip
            }
        } elseif (is_callable($ctx['session'])) {
            try {
                $session = ($ctx['session'])();
                if ($session !== null) {
                    $context['session'] = $session;
                }
            } catch (Throwable) {
                // Resolver threw — skip rather than crashing the logger itself
            }
        }

        foreach ($ctx['extra'] as $key => $resolver) {
            try {
                $value = $resolver();
                if ($value !== null) {
                    $context[$key] = $value;
                }
            } catch (Throwable) {
                // Resolver threw — skip rather than crashing the logger itself
            }
        }

        if ($config->exception['trace']) {
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
     * Alert handlers are only called when the level matches `alertLevels` in config.
     * Each handler is resolved as: callable, invokable class, or class with handle().
     * A broken handler never takes down the logger — all calls are wrapped in try/catch.
     *
     * @param string $level
     * @param string $message
     */
    public function log($level, $message, array $context = []): void
    {
        parent::log($level, $message, $context);

        /** @var LoggingExtended $config */
        $config = config('LoggingExtended');

        $alerts = $config->exception['alerts'];

        if ($alerts['handlers'] === [] || $alerts['levels'] === []) {
            return;
        }

        if (! in_array(strtolower((string) $level), $alerts['levels'], true)) {
            return;
        }

        $throttle = (int) $alerts['throttle'];

        if ($throttle > 0) {
            $cacheKey = 'lv_alert_' . md5($level . $message);
            if ($this->alertCache()->get($cacheKey) !== null) {
                return;
            }
        }

        $alert = new LogAlert(
            level:     strtolower((string) $level),
            message:   (string) $message,
            context:   $context,
            timestamp: microtime(true),
        );

        foreach ($alerts['handlers'] as $handler) {
            try {
                if (is_callable($handler)) {
                    $handler($alert);
                } elseif (is_string($handler)) {
                    $instance = new $handler();
                    if (is_callable($instance)) {
                        $instance($alert);
                    } else {
                        $instance->handle($alert);
                    }
                }
            } catch (Throwable) {
                // A broken handler must never take down the logger itself
            }
        }

        if ($throttle > 0) {
            $this->alertCache()->save($cacheKey, true, $throttle);
        }
    }

    /**
     * Returns the isolated file cache used for alert throttling.
     * Initialised once and shared across all calls (static property).
     */
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
