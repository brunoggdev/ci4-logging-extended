<?php

declare(strict_types=1);

namespace Brunoggdev\LoggingExtended;

/**
 * Represents a log event passed to alert handlers.
 *
 * Handlers registered in exception()['alerts']['handlers'] receive an instance
 * of this class whenever a matching log level is triggered.
 */
class LogAlert
{
    /**
     * @param string  $level     PSR-3 log level in lowercase e.g. 'error', 'critical'
     * @param string  $message   The raw log message (before context interpolation)
     * @param array{
     *     class?: string,
     *     message?: string,
     *     location?: string,
     *     request?: array{method: string, url: string},
     *     user?: array<string, mixed>,
     *     session?: array<string, mixed>,
     *     trace?: string,
     *     ...
     * } $context Key/value pairs passed alongside the log call.
     *            'class', 'message', 'location', 'request', 'user', 'session', and 'trace'
     *            are populated by exception() when enabled. Any keys passed directly to
     *            logger()->*() calls are also included.
     * @param float   $timestamp Unix epoch with microseconds from microtime(true) — timezone-agnostic.
     *                           Format for display: DateTime::createFromFormat('U.u', number_format($alert->timestamp, 6, '.', ''))
     *                               ->setTimezone(new \DateTimeZone(app_timezone()))
     */
    public function __construct(
        public readonly string $level,
        public readonly string $message,
        public readonly array $context,
        public readonly float $timestamp,
    ) {}
}
