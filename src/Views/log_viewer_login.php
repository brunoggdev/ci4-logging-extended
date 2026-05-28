<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log Viewer — Login</title>
    <script>
    (function () {
        var BSW_KEY   = 'lv-bootswatch';
        var THEME_KEY = 'lv-theme';
        var bsw   = localStorage.getItem(BSW_KEY) || 'flatly';
        var theme = localStorage.getItem(THEME_KEY) ||
                    (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.setAttribute('data-bs-theme', theme);
        document.write('<link id="bs-css" rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.8/dist/' + bsw + '/bootstrap.min.css">');
    }());
    </script>
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100">
    <div style="width: 100%; max-width: 360px;" class="px-3">
        <div class="text-center mb-4">
            <h5 class="fw-semibold mb-0">Log Viewer</h5>
            <small class="text-secondary">Enter your password to continue</small>
        </div>

        <form method="post" action="<?= site_url(esc($basePath) . '/login') ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="redirect" value="<?= esc($redirect) ?>">

            <?php if ($error): ?>
                <div class="alert alert-danger py-2 small">Incorrect password.</div>
            <?php endif ?>

            <div class="mb-3">
                <input
                    type="password"
                    name="password"
                    class="form-control <?= $error ? 'is-invalid' : '' ?>"
                    placeholder="Password"
                    autofocus
                >
            </div>

            <button type="submit" class="btn btn-primary w-100">Login</button>
        </form>
    </div>
</body>
</html>
