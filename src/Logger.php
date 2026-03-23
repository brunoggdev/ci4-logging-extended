<?php

declare(strict_types=1);

namespace Brunoggdev\LoggingExtended;

use CodeIgniter\Log\Logger as CI4Logger;

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
    /**
     * Overrides CI4's interpolate() to also append any context keys
     * that were not consumed by placeholders in the message.
     *
     * @param string $message
     * @param array  $context
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

        foreach ($extras as $key => $val) {
            if (is_null($val)) {
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

        return $message . ' | ' . implode(' ', $parts);
    }
}
