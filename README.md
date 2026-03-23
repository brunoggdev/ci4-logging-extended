# ci4-logging-extended

Extended logging for CodeIgniter 4 — part of the `ci4-*-extended` series.

Two things in one package:

1. **`php spark log:tail`** — Watch your log file in real time with colorized, filtered output.
2. **Context arrays actually work** — CI4's native logger silently drops any context keys that don't have a matching `{placeholder}` in the message. This package fixes that by appending leftover keys as `key=value` pairs automatically.

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

Before this package, passing a context array without matching placeholders was silently discarded:
```php
// ❌ Native CI4 — context is dropped, you only see "copyProducts failed"
log_message('error', 'copyProducts failed', [
    'source' => $sourceId,
    'target' => $targetId,
    'error'  => $e->getMessage(),
]);
```

After wiring the extended logger, the same call produces:
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