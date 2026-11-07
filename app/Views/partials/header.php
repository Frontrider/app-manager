<!doctype html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Local Host</title>
    <script>
        try {
            const savedTheme = localStorage.getItem('appstore-theme');
            if (savedTheme === 'light' || savedTheme === 'dark') {
                document.documentElement.dataset.theme = savedTheme;
            }
        } catch (error) {
            console.warn('Could not restore the saved theme.', error);
        }
    </script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">
    <style>
        @view-transition { navigation: auto; }
        ::view-transition-old(root), ::view-transition-new(root) { animation-duration: 120ms; }
        :root { color-scheme: light; --app-background: #f5f7fa; --app-logo-background: #eef3f8; }
        :root[data-theme="dark"] { color-scheme: dark; --app-background: #161b22; --app-logo-background: #242d38; }
        body { min-height: 100vh; background: var(--app-background); }
        .hero.is-appstore { background: linear-gradient(120deg, #173b63, #276c91); }
        .app-card { height: 100%; display: flex; flex-direction: column; overflow: hidden; }
        .app-logo { display: block; width: 100%; height: 160px; object-fit: contain; padding: 1.5rem; background: var(--app-logo-background); }
        .app-card .card-content { flex: 1; }
        .app-card .level-left { flex: 1; min-width: 0; margin-right: 0.75rem; }
        .app-card h2 { overflow-wrap: anywhere; }
        .app-path { overflow-wrap: anywhere; }
        .card-footer form { width: 100%; }
        body { display: flex; flex-direction: column; }
        .app-catalog { flex: 1; display: flex; }
        .app-catalog-container { display: flex; flex-direction: column; }
        .app-catalog-layout { flex: 1; }
        .theme-control { display: inline-flex; align-items: center; gap: 0.75rem; color: #fff; cursor: pointer; }
        .theme-switch { position: relative; display: inline-flex; width: 3.5rem; height: 2rem; }
        .theme-switch input { position: absolute; inset: 0; width: 100%; height: 100%; margin: 0; opacity: 0; cursor: pointer; }
        .theme-track { width: 100%; border-radius: 999px; background: #bce3fa; pointer-events: none; transition: background 150ms; }
        .theme-thumb { position: absolute; top: 0.25rem; left: 0.25rem; width: 1.5rem; height: 1.5rem; display: grid; place-items: center; border-radius: 50%; background: #fff4cf; color: #956000; transition: transform 150ms; }
        .theme-thumb svg { width: 1.125rem; height: 1.125rem; }
        .theme-moon { display: none; }
        :root[data-theme="dark"] .theme-track { background: #111c35; }
        :root[data-theme="dark"] .theme-thumb { transform: translateX(1.5rem); background: #dce6ff; color: #273966; }
        :root[data-theme="dark"] .theme-sun { display: none; }
        :root[data-theme="dark"] .theme-moon { display: inline; }
        .theme-switch input:focus-visible + .theme-track { outline: 2px solid #fff; outline-offset: 3px; }
        @media (prefers-reduced-motion: reduce) {
            .theme-track, .theme-thumb { transition: none; }
            ::view-transition-old(root), ::view-transition-new(root) { animation: none; }
        }
    </style>
</head>
<body>
<section class="hero is-appstore">
    <div class="hero-body">
        <div class="container">
            <div class="level">
                <div class="level-left">
                    <p class="title has-text-white mb-0">Applications</p>
                </div>
                <div class="level-right">
                    <?php if (session()->get('ldap_user')): ?>
                        <form method="post" action="<?= esc(site_url('logout'), 'attr') ?>" class="mr-4">
                            <?= csrf_field() ?>
                            <button type="submit" class="button is-light is-small">Sign out</button>
                        </form>
                    <?php endif ?>
                    <label class="theme-control">
                        <span class="theme-switch">
                            <input id="theme-toggle" type="checkbox" role="switch" aria-label="Dark mode">
                            <span class="theme-track" aria-hidden="true">
                                <span class="theme-thumb">
                                    <svg class="theme-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                                        <circle cx="12" cy="12" r="4"/>
                                        <path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/>
                                    </svg>
                                    <svg class="theme-moon" viewBox="0 0 24 24" fill="currentColor">
                                        <path d="M20.5 14.5A9 9 0 0 1 9.5 3.5a9 9 0 1 0 11 11Z"/>
                                    </svg>
                                </span>
                            </span>
                        </span>
                    </label>
                </div>
            </div>
            <p id="theme-message" class="has-text-white-ter is-size-7" role="status"></p>
        </div>
    </div>
</section>
