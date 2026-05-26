<?php

declare(strict_types=1);

namespace Brunoggdev\LoggingExtended\Config;

use Brunoggdev\LoggingExtended\Logger;
use CodeIgniter\Config\Services as BaseServices;

class Services extends BaseServices
{
    public static function logger(bool $getShared = true): Logger
    {
        if ($getShared) {
            return static::getSharedInstance('logger');
        }

        return new Logger(config('Logger'));
    }
}
