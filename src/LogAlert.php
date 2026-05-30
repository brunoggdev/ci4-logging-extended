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
     * @param \CodeIgniter\I18n\Time $timestamp Alert time in the app timezone.
     */
    public function __construct(
        public readonly string $level,
        public readonly string $message,
        public readonly array $context,
        public readonly \CodeIgniter\I18n\Time $timestamp,
    ) {}
}
