<?php

declare(strict_types=1);

namespace Brunoggdev\LoggingExtended\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class LoggingExtendedPublish extends BaseCommand
{
    protected $group       = 'LoggingExtended';
    protected $name        = 'logging-extended:publish';
    protected $description = 'Publishes the LoggingExtended config file into the application config folder.';

    public function run(array $params): void
    {
        $dest = APPPATH . 'Config/LoggingExtended.php';

        if (file_exists($dest)) {
            $overwrite = CLI::prompt('LoggingExtended.php already exists. Overwrite?', ['y', 'n']) === 'y';

            if (! $overwrite) {
                CLI::write('Publish cancelled.', 'yellow');

                return;
            }
        }

        $contents = <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Config;

            use Brunoggdev\LoggingExtended\Config\LoggingExtended as BaseLoggingExtended;

            class LoggingExtended extends BaseLoggingExtended
            {

                /* ------------------------------------------------------------------------
                | Although it may look unconventional for a CI4 config file, the method
                | approach used here lets you use dynamic calls like env() and define
                | closures inline — so every config decision lives in one place and reads
                | as a single coherent unit, with no need for a constructor or for splitting
                | static declarations and dynamic overrides across two locations.
                ------------------------------------------------------------------------ */
                 

                protected function viewer(): array
                {
                    return [
                                    // Set to false to completely hide the viewer and its routes
                                    'enabled' => true,

                                    // Controls access:
                                    //   null              = deny outside development (default)
                                    //   self::GATE_LOGIN  = built-in login page (requires LOG_VIEWER_PASSWORD_HASH in .env)
                                    //   fn() => bool      = custom callable e.g. fn() => auth()->loggedIn()
                                    'gate' => null,

                                    // URL path and optional CI4 filter aliases applied before the gate
                                    'routes' => [
                                        'path'    => 'logs',
                                        'filters' => [],
                                    ],

                                    // IDE deep links for stack frames — set wslDistro if on WSL
                                    // e.g. 'wslDistro' => env('LE_WSL_DISTRO')
                                    // Set serverPath + localPath to rewrite server paths to your local equivalent
                                    // e.g. 'serverPath' => '/var/www/myapp/', 'localPath' => env('LE_LOCAL_PATH')
                                    'deeplink' => [
                                        'ide'        => 'vscode',
                                        'wslDistro'  => null,
                                        'serverPath' => null,
                                        'localPath'  => null,
                                    ],

                                    // Entries displayed per page
                                    'perPage' => 50,
                    ];
                }

                protected function exception(): array
                {
                    return [
                                    // Include the full stack trace in each exception log entry
                                    'trace' => true,

                                    'request' => [
                                        // Log request method + URL — enables request.method=, request.url= search
                                        'enabled' => true,
                                        // Include GET/POST/JSON body — off by default (privacy)
                                        'params'  => false,
                                        // true = all headers; array of names = allow-list; false = off (default)
                                        'headers' => false,
                                        // Keys redacted from params and headers (case-insensitive, applied recursively)
                                        'redact'  => [
                                            'password', 'passwd', 'pass', 'secret', 'token',
                                            'api_key', 'apikey', 'authorization', 'auth',
                                            'cookie', 'credit_card', 'card_number', 'cvv', 'cc',
                                        ],
                                    ],

                                    'context' => [
                                        // Callable returning user data — enables user.id=, user.email= search
                                        // e.g. fn() => ['id' => auth()->id(), 'email' => auth()->user()?->email]
                                        'user' => null,

                                        // true = capture all session data; callable for specific keys — off by default (privacy)
                                        'session' => false,

                                        // Named callables for arbitrary context — each key becomes searchable
                                        // e.g. ['tenant' => fn() => session('tenant_id')]
                                        'extra' => [],
                                    ],

                                    'alerts' => [
                                        // Callables, invokable classes, or classes with handle(LogAlert)
                                        // e.g. [SlackAlertHandler::class]
                                        'handlers' => [],

                                        // Log levels that trigger handlers e.g. ['critical', 'error']
                                        'levels' => [],

                                        // How long to suppress repeated alerts for the same level + message; 0 = no throttling
                                        // e.g. 15 * MINUTE, 2 * HOUR
                                        'throttle' => 15 * MINUTE,
                                    ],
                                ];
                }
            }
            PHP;

        // Normalize indentation (heredoc uses spaces for readability, strip leading 12 spaces)
        $contents = preg_replace('/^ {12}/m', '', $contents);

        if (! file_put_contents($dest, $contents)) {
            CLI::error('Error publishing LoggingExtended.php.');

            return;
        }

        CLI::write('LoggingExtended.php published successfully.', 'green');
        CLI::write('Edit ' . $dest . ' to configure the log viewer and exception logging.', 'white');
    }
}
