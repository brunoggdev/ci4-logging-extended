# ci4-logging-extended

Extended logging for CodeIgniter 4 — part of the `ci4-*-extended` series.

Two things in one package:

1. **`php spark log:tail`** — Watch your log file in real time with colorized, filtered output.
2. **Richer context serialization** — CI4's native logger follows the PSR-3 spec strictly: context keys without a matching `{placeholder}` in the message are silently discarded. This package extends that behavior by appending leftover keys as `key=value` pairs automatically.

---

## Installation
```bash
composer require brunoggdev/ci4-logging-extended
```

---

## log:tail
```bash
php spark log:tail
```

On startup it shows the last 20 lines of today's log, then streams new entries as they arrive. Rolls over automatically at midnight when a new daily file is created.

### Options

| Option | Description | Default |
|---|---|---|
| `-level` | Filter by log level | — |
| `-filter` | Filter lines containing this text (case-insensitive) | — |
| `-lines` | Lines to show on startup (use `0` to skip) | `20` |

### Examples
```bash
php spark log:tail                                        # watch everything
php spark log:tail -level error -lines 0                 # only errors, no history
php spark log:tail -filter payment -lines 100            # keyword filter, last 100 lines
php spark log:tail -level warning -filter checkout       # combine filters
```

### Output
```
  ERROR      2026-03-20 14:32:01  copyUnityProducts failed | source_unity_id=42 error="Timeout"
  WARNING    2026-03-20 14:32:05  Retry scheduled | job=CopyProducts attempt=2
  INFO       2026-03-20 14:32:10  Job completed successfully
```

- Level is colorized: **red** for `error`/`critical`/`alert`/`emergency`, **yellow** for `warning`, **cyan** for `notice`, **green** for `info`, **gray** for `debug`
- The context part (after `|`) is highlighted in **cyan** to visually separate it from the main message

---

## Context serialization

This package ships with `Brunoggdev\LoggingExtended\Logger`, an improved drop-in replacement for CI4's native logger, and a `logger()` helper function to quickly get an instance of it.

Wire it up by adding the following to `app/Config/Services.php`:
```php
public static function logger(bool $getShared = true)
{
    if ($getShared) {
        return static::getSharedInstance('logger');
    }

    return new \Brunoggdev\LoggingExtended\Logger(config('Logger'));
}
```

PSR-3 only interpolates context keys that have a matching `{placeholder}` in the message — everything else is discarded by design. With the extended logger, leftover keys are appended automatically:
```php
// Native CI4 (PSR-3 strict) — context is discarded, you only see "copyProducts failed"
log_message('error', 'copyProducts failed', [
    'source' => $sourceId,
    'target' => $targetId,
    'error'  => $e->getMessage(),
]);
```

With the extended logger wired in, the same call produces:
```
ERROR - 2026-03-20 14:32:01 --> copyProducts failed | source=42 target=99 error="Division by zero"
```

PSR-3 placeholder interpolation still works as before — keys consumed by `{placeholders}` are not duplicated in the appended context:
```php
logger()->error('Job {job} failed on attempt {attempt}', [
    'job'     => 'SendEmail',   // consumed by {job}
    'attempt' => 3,             // consumed by {attempt}
    'user_id' => 99,            // not a placeholder — appended
]);
// ERROR - ... --> Job SendEmail failed on attempt 3 | user_id=99
```

### Logging exceptions

The extended logger adds an `exception()` method that formats a `Throwable` into a clean log entry:

```php
try {
    // ...
} catch (Throwable $e) {
    logger()->exception($e);            // defaults to 'error' level
    logger()->exception($e, 'warning'); // or any PSR-3 level
}
// ERROR - ... --> [RuntimeException] Something went wrong
```

Need to also send exceptions to an external tracker (Sentry, GlitchTip, etc.)? Extend the logger and override `exception()` — calling `parent::` keeps the file log entry:

```php
use Brunoggdev\LoggingExtended\Logger;

class GlitchTipLogger extends Logger
{
    public function exception(Throwable $e, string $level = 'error'): void
    {
        \Sentry\captureException($e);
        parent::exception($e, $level);
    }
}
```

Then wire your subclass in `app/Config/Services.php` instead of the base one.

---

### Using a custom Logger subclass?

If you have a class that extends CI4's `Logger` (e.g. `SentryLogger`, `GoogleLogger`), just swap the parent import and you get context serialization for free:
```php
// Before
use CodeIgniter\Log\Logger;

// After
use Brunoggdev\LoggingExtended\Logger;
```

---

## Related packages

- [brunoggdev/ci4-events-extended](https://github.com/brunoggdev/ci4-events-extended)