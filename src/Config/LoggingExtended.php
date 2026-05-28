<?php

declare(strict_types=1);

namespace Brunoggdev\LoggingExtended\Config;

use CodeIgniter\Config\BaseConfig;

class LoggingExtended extends BaseConfig
{
    /**
     * Sentinel value for `viewer()['gate']` that activates the built-in login page.
     *
     * Set `'gate' => LoggingExtended::GATE_LOGIN` and run
     * `php spark log-viewer:set-password` to enable password-protected access.
     */
    public const GATE_LOGIN = '__lv_login__';

    public array $viewer    = [];
    public array $exception = [];

    public function __construct()
    {
        parent::__construct();

        $this->viewer    = $this->viewer();
        $this->exception = $this->exception();

        $this->validateViewer();
        $this->validateException();
    }

    /**
     * Re-runs the constructor instead of restoring serialized state so that
     * closures defined in viewer() / exception() survive spark optimize caching.
     */
    public static function __set_state(array $state): static
    {
        return new static();
    }

    /**
     * Log Viewer configuration.
     *
     * - **enabled**:  Activates the web log viewer and registers its routes.
     * - **gate**:     Controls access. Three options:
     *                 - callable returning bool — your own logic, e.g. fn() => auth()->loggedIn()
     *                 - `LoggingExtended::GATE_LOGIN` — built-in login page (requires LOG_VIEWER_PASSWORD_HASH in .env)
     *                 - `null` — deny with 404
     * - **routes**:   - `path`: URL path where the viewer is accessible (e.g. 'logs' → your-app.test/logs)
     *                 - `filters`: CI4 filter aliases applied before the gate. Example: ['auth']
     * - **deeplink**: IDE deep link configuration for stack frame links.
     *                 - `ide`: 'vscode', 'phpstorm', or null to disable
     *                 - `wslDistro`: VSCode WSL distro name e.g. 'Ubuntu'
     *                 - `serverPath` / `localPath`: rewrite server paths to local equivalents
     * - **perPage**:  Entries per page.
     *
     * @return array{enabled: bool, gate: callable|string|null, routes: array{path: string, filters: list<string>}, deeplink: array{ide: string|null, wslDistro: string|null, serverPath: string|null, localPath: string|null}, perPage: int}
     */
    protected function viewer(): array
    {
        return [
            'enabled'  => true,
            'gate'     => fn () => ENVIRONMENT === 'development',
            'routes'   => [
                'path'    => 'logs',
                'filters' => [],
            ],
            'deeplink' => [
                'ide'        => 'vscode',
                'wslDistro'  => null,
                'serverPath' => null,
                'localPath'  => null,
            ],
            'perPage'  => 50,
        ];
    }

    /**
     * Exception logging configuration.
     *
     * - **trace**:   Include the full stack trace in the log entry.
     * - **request**: - `enabled`: log method + URL (searchable: request.method, request.url)
     *                - `params`: GET/POST/JSON body (off by default — privacy)
     *                - `headers`: true = all headers; array of names = allow-list; false = off (default)
     *                - `redact`: keys redacted from params and headers (case-insensitive, recursive)
     * - **context**: - `user`: callable returning user data. Enables user.email=, user.id= search.
     *                - `session`: true = all session data; callable for specific keys
     *                - `extra`: ['label' => callable] for arbitrary additional context
     * - **alerts**:  - `handlers`: callables, invokable classes, or classes with handle(LogAlert)
     *                - `levels`: log levels that trigger handlers e.g. ['critical', 'error']
     *                - `throttle`: minutes to suppress repeated alerts for the same level + message; 0 = off
     *
     * @return array{trace: bool, request: array{enabled: bool, params: bool, headers: bool|list<string>, redact: list<string>}, context: array{user: callable|null, session: bool|callable, extra: array<string, callable>}, alerts: array{handlers: list<callable|string>, levels: list<string>, throttle: int}}
     */
    protected function exception(): array
    {
        return [
            'trace'   => true,
            'request' => [
                'enabled' => true,
                'params'  => false,
                'headers' => false,
                'redact'  => [
                    'password', 'passwd', 'pass', 'secret', 'token',
                    'api_key', 'apikey', 'authorization', 'auth',
                    'cookie', 'credit_card', 'card_number', 'cvv', 'cc',
                ],
            ],
            'context' => [
                'user'    => null,
                'session' => false,
                'extra'   => [],
            ],
            'alerts'  => [
                'handlers' => [],
                'levels'   => [],
                'throttle' => 15 * MINUTE,
            ],
        ];
    }

    /**
     * Validates that all required viewer config keys are present.
     *
     * @throws \RuntimeException
     */
    public function validateViewer(): void
    {
        $required = ['enabled', 'gate', 'routes', 'deeplink', 'perPage'];
        $missing  = array_diff($required, array_keys($this->viewer));

        if ($missing !== []) {
            throw new \RuntimeException(
                'LoggingExtended::viewer() is missing key(s): ' . implode(', ', $missing) . '. '
                . 'Run `php spark logging-extended:publish` to regenerate your config.'
            );
        }
    }

    /**
     * Validates that all required exception config keys are present.
     *
     * @throws \RuntimeException
     */
    public function validateException(): void
    {
        $required = ['trace', 'request', 'context', 'alerts'];
        $missing  = array_diff($required, array_keys($this->exception));

        if ($missing !== []) {
            throw new \RuntimeException(
                'LoggingExtended::exception() is missing key(s): ' . implode(', ', $missing) . '. '
                . 'Run `php spark logging-extended:publish` to regenerate your config.'
            );
        }
    }
}
