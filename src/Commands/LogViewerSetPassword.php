<?php

declare(strict_types=1);

namespace Brunoggdev\LoggingExtended\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class LogViewerSetPassword extends BaseCommand
{
    protected $group       = 'LoggingExtended';
    protected $name        = 'log-viewer:set-password';
    protected $description = 'Set or update the Log Viewer login password (writes hash to .env).';
    protected $usage       = 'log-viewer:set-password';

    public function run(array $params): void
    {
        $password = CLI::prompt('Password', null, 'required');
        $confirm  = CLI::prompt('Confirm password', null, 'required');

        if ($password !== $confirm) {
            CLI::error('Passwords do not match.');
            return;
        }

        $hash    = password_hash($password, PASSWORD_BCRYPT);
        $envPath = ROOTPATH . '.env';

        if (! file_exists($envPath)) {
            CLI::error('.env file not found at: ' . $envPath);
            return;
        }

        $contents = file_get_contents($envPath);
        // Wrap in double quotes — bcrypt hashes contain $ which DotEnv would misinterpret unquoted
        $line     = 'LOG_VIEWER_PASSWORD_HASH="' . $hash . '"';

        if (str_contains($contents, 'LOG_VIEWER_PASSWORD_HASH=')) {
            // Use a callback so $line is never interpreted as a preg replacement string —
            // bcrypt hashes contain $ which would be treated as backreferences otherwise.
            $contents = preg_replace_callback(
                '/^LOG_VIEWER_PASSWORD_HASH=.*$/m',
                fn () => $line,
                $contents,
            );
        } else {
            $contents = rtrim($contents) . PHP_EOL . $line . PHP_EOL;
        }

        file_put_contents($envPath, $contents);

        CLI::write('Password set. All existing sessions will be invalidated on next request.', 'green');
    }
}
