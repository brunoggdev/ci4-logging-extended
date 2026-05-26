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
                public array $viewer = [
                    'enabled'    => true,       // set to false to completely hide the viewer and its routes
                    'routesPath' => 'logs',     // URL path: your-app.test/logs
                    'gate'       => null,       // null = deny; defaults to development-only (set in parent constructor)
                    'deeplink'   => [
                        'ide'        => 'vscode',   // 'vscode', 'phpstorm', or null to disable links
                        'wslDistro'  => null,       // e.g. 'Ubuntu' — required for VSCode links on WSL
                        'serverPath' => null,       // path prefix in the logs e.g. '/var/www/myapp/'
                        'localPath'  => null,       // your local equivalent e.g. '/home/user/project/'
                    ],
                    'perPage'    => 50,         // entries per page
                ];

                public array $exception = [
                    'trace'   => true,      // include full stack trace
                    'request' => true,      // log method + URL (searchable: request.method, request.url)
                    'params'  => false,     // GET/POST/JSON body — off by default (privacy)
                    'headers' => false,     // request headers — off by default (may expose tokens/cookies)
                    'redact'  => [          // keys redacted from params and headers (case-insensitive, recursive)
                                    'password',
                                    'passwd',
                                    'pass',
                                    'secret',
                                    'token',
                                    'api_key',
                                    'apikey',
                                    'authorization',
                                    'auth',
                                    'credit_card',
                                    'card_number',
                                    'cvv',
                                    'cc',
                    ],
                    'user'    => null,      // callable returning user data (searchable: user.email, user.id)
                    'session' => false,     // true = all session data; callable for filtered keys — off by default (privacy)
                    'context' => [],        // ['label' => callable] for custom context (searchable: label.key)
                ];

                public function __construct()
                {
                    // Set your overrides before parent::__construct(), e.g.:
                    // $this->viewer['gate']       = fn() => auth()->loggedIn();
                    // $this->exception['user']    = fn() => ['id' => auth()->id(), 'email' => auth()->user()?->email];
                    // $this->exception['session'] = true;
                    // $this->exception['context'] = ['tenant' => fn() => session('tenant_id')];

                    parent::__construct(); // applies defaults and validates — keep at the bottom
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
