<?php

declare(strict_types=1);

namespace Brunoggdev\LoggingExtended\Config;

use CodeIgniter\Config\BaseConfig;

class LoggingExtended extends BaseConfig
{
    /**
     * Log viewer configuration.
     *
     * - **enabled**:    Activates the web log viewer and registers its routes. When false, the viewer is completely invisible.
     * - **routesPath**: URL path where the viewer will be accessible. Example: 'logs' → your-app.test/logs
     * - **gate**:       Callable that must return true to allow access. null = deny (404).
     *                   Defaults to development-only access. Override for production:
     *                   Example: fn() => auth()->loggedIn()
     * - **deeplink**:   IDE deep link configuration for file/line links in the viewer.
     *                   - `ide`: Scheme to use. Supported: 'vscode', 'phpstorm', null (disables links).
     *                   - `wslDistro`: VSCode WSL distro name e.g. 'Ubuntu'. Required for WSL.
     *                                  Tip: use env() here if your team has mixed environments (WSL / native Linux / Mac).
     *                   - `serverPath`: Path prefix as it appears in the logs (e.g. '/var/www/myapp/').
     *                   - `localPath`: Your local equivalent (e.g. '/home/user/project/'). Both must be set to rewrite.
     * - **perPage**:    Number of log entries to display per page.
     *
     * @var array{enabled: bool, routesPath: string, gate: callable|null, deeplink: array{ide: string|null, wslDistro: string|null, serverPath: string|null, localPath: string|null}, perPage: int}
     */
    public array $viewer = [
        'enabled'    => true,
        'routesPath' => 'logs',
        'gate'       => null,
        'deeplink'   => [
            'ide'        => 'vscode',
            'wslDistro'  => null,
            'serverPath' => null,
            'localPath'  => null,
        ],
        'perPage'    => 50,
    ];

    /**
     * Exception logging configuration.
     *
     * - **trace**:   Include the full stack trace in the log entry.
     *               Disable for minimal one-line entries (e.g. when forwarding to an external tracker).
     * - **request**: Include request method and URL as a structured context key.
     *               Enables dot-notation search: request.method=POST, request.url=checkout
     * - **params**:   Include GET query params, POST fields, and JSON body.
     *                Off by default — enable only if aware of what may be captured.
     *                Keys matching `redact` are replaced with '[REDACTED]'.
     * - **headers**:  Include request headers. Off by default — headers often carry tokens and cookies.
     *                Header names are matched against `redact` (e.g. Authorization → [REDACTED]).
     * - **redact**:   Keys to redact when params or headers is enabled.
     *               Matched case-insensitively and applied recursively to nested arrays.
     * - **user**:    Callable returning user data to include (e.g. id, email, role). null = skip.
     *               Enables dot-notation search: user.email=foo, user.id=1
     *               Example: fn() => ['id' => auth()->id(), 'email' => auth()->user()?->email]
     * - **session**: true = include all session data automatically. false = off (privacy concern).
     *               Pass a callable to capture only specific keys.
     *               Enables dot-notation search: session.key=value
     * - **context**: Named callable resolvers for arbitrary extra context.
     *               Each key becomes a flat log entry label and a dot-notation search prefix.
     *               Return null from a resolver to omit that key.
     *               Example: ['tenant' => fn() => session('tenant_id')]
     *
     * @var array{trace: bool, request: bool, params: bool, headers: bool, redact: list<string>, user: callable|null, session: bool|callable, context: array<string, callable>}
     */
    public array $exception = [
        'trace'   => true,
        'request' => true,
        'params'  => false,
        'headers' => false,
        'redact'  => [
            'password', 'passwd', 'pass', 'secret', 'token',
            'api_key', 'apikey', 'authorization', 'auth',
            'credit_card', 'card_number', 'cvv', 'cc',
        ],
        'user'    => null,
        'session' => false,
        'context' => [],
    ];

    public function __construct()
    {
        parent::__construct();

        // Default gate: allow in development, deny elsewhere.
        // Subclasses can override this by assigning $this->viewer['gate'] in their own
        // constructor BEFORE calling parent::__construct() — the ??= below will then no-op.
        $this->viewer['gate'] ??= function () {
            if (ENVIRONMENT === 'development') {
                return true;
            }

            // deny in production — override with your own logic, e.g. fn() => auth()->loggedIn()
            return false;
        };

        $this->validateViewer();
        $this->validateException();
    }

    /**
     * Validates that all required viewer config keys are present.
     *
     * @throws \RuntimeException if the $viewer array is malformed.
     */
    public function validateViewer(): void
    {
        $required = ['enabled', 'routesPath', 'gate', 'deeplink', 'perPage'];
        $missing  = array_diff($required, array_keys($this->viewer));

        if ($missing !== []) {
            throw new \RuntimeException(
                'LoggingExtended::$viewer is missing key(s): ' . implode(', ', $missing) . '. '
                . 'Run `php spark logging-extended:publish` to regenerate your config.'
            );
        }
    }

    /**
     * Validates that all required exception config keys are present.
     *
     * @throws \RuntimeException if the $exception array is malformed.
     */
    public function validateException(): void
    {
        $required = ['trace', 'request', 'params', 'headers', 'redact', 'user', 'session', 'context'];
        $missing  = array_diff($required, array_keys($this->exception));

        if ($missing !== []) {
            throw new \RuntimeException(
                'LoggingExtended::$exception is missing key(s): ' . implode(', ', $missing) . '. '
                . 'Run `php spark logging-extended:publish` to regenerate your config.'
            );
        }
    }
}
