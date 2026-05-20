<?php

use Brunoggdev\LoggingExtended\Logger;

if (! function_exists('logger')) {
    /**
     * Shortcut for interacting with the logger service
     */
    function logger(bool $shared = true): Logger
    {
        return service('logger', $shared);
    }
}
