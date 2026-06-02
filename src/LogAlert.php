<?php

declare(strict_types=1);

namespace Brunoggdev\LoggingExtended;

use Throwable;

/**
 * Represents a log event passed to alert handlers.
 *
 * Handlers registered in exception()['alerts']['handlers'] receive an instance
 * of this class whenever a matching log level is triggered.
 */
class LogAlert
{
    /**
     * @param string                 $level     PSR-3 log level in lowercase e.g. 'error', 'critical'.
     * @param string                 $message   Human-readable message. For exception() calls this is the
     *                                          exception message. For plain log calls this is the
     *                                          interpolated log message.
     * @param array                  $captures  Configured captures only: request, user, session, and extra
     *                                          keys as defined in exception()['captures']. Empty for plain
     *                                          log calls.
     * @param array                  $context   Everything else: CI4 native handler keys (exception, routeInfo,
     *                                          exFile, exLine, etc.) and any key/value pairs passed directly
     *                                          to logger()->*() calls. Intentionally left untouched.
     * @param \CodeIgniter\I18n\Time $timestamp Alert time in the app timezone.
     * @param Throwable|null         $exception The caught exception, when triggered via exception() or CI4's
     *                                          native handler. Gives handlers direct access to class, trace,
     *                                          previous exception, etc.
     * @param string|null            $location  'file.php:line' of the throw site. Smart-resolved to skip
     *                                          factory/named-constructor frames.
     */
    public function __construct(
        public readonly string $level,
        public readonly string $message,
        public readonly array $captures,
        public readonly array $context,
        public readonly \CodeIgniter\I18n\Time $timestamp,
        public readonly ?Throwable $exception = null,
        public readonly ?string $location = null,
    ) {}
}
