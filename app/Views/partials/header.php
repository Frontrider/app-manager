<!doctype html>
<?php helper('preferences');
$styleVersion = filemtime(FCPATH . 'styles/style.css') ?: time();
?>
<html lang="en" data-theme="<?php if (preference('dark_mode')) echo 'dark'; else echo 'light' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Local Host</title>
    <link rel="stylesheet" href="/styles/bulma.min.css">
    <link rel="stylesheet" href="/styles/style.css?v=<?= esc((string) $styleVersion, 'attr') ?>">
    <style>
        .theme-control {
            display: inline-flex;
            align-items: center;
            color: #fff;
            cursor: pointer;
        }

        .theme-switch {
            position: relative;
            display: inline-flex;
            width: 3.5rem;
            height: 2rem;
        }

        .theme-switch__input {
            position: absolute;
            inset: 0;
            z-index: 1;
            width: 100%;
            height: 100%;
            margin: 0;
            opacity: 0;
            cursor: pointer;
        }

        .theme-track {
            width: 100%;
            border-radius: 999px;
            background: #bce3fa;
            pointer-events: none;
            transition: background-color 0.5s ease;
        }

        .theme-thumb {
            position: absolute;
            top: 0.25rem;
            left: 0.25rem;
            display: grid;
            width: 1.5rem;
            height: 1.5rem;
            place-items: center;
            border-radius: 50%;
            background: #fff4cf;
            color: #956000;
            transition: transform 0.5s ease, background-color 0.5s ease, color 0.5s ease;
        }

        .theme-thumb svg {
            width: 1.125rem;
            height: 1.125rem;
        }

        .theme-moon {
            display: none;
        }

        .theme-switch__input:checked ~ .theme-track {
            background: #111c35;
        }

        .theme-switch__input:checked ~ .theme-track .theme-thumb {
            transform: translateX(1.5rem);
            background: #dce6ff;
            color: #273966;
        }

        .theme-switch__input:checked ~ .theme-track .theme-sun {
            display: none;
        }

        .theme-switch__input:checked ~ .theme-track .theme-moon {
            display: inline;
        }

        .theme-switch__input:focus-visible ~ .theme-track {
            outline: 2px solid #fff;
            outline-offset: 3px;
        }

        @media (prefers-reduced-motion: reduce) {
            .theme-track,
            .theme-thumb {
                transition: none;
            }
        }
    </style>
</head>
<body hx-ext="morph">
<nav class="navbar is-dark is-link" role="navigation" aria-label="Main navigation">
    <div class="container">
        <div class="navbar-brand">
            <a class="navbar-item has-text-weight-bold" href="<?= esc(site_url('/'), 'attr') ?>">Local Host</a>
            <a role="button" class="navbar-burger" aria-label="Open navigation menu" aria-expanded="false"
               aria-controls="main-navigation" data-navbar-burger>
                <span aria-hidden="true"></span>
                <span aria-hidden="true"></span>
                <span aria-hidden="true"></span>
            </a>
        </div>
        <div class="navbar-menu" id="main-navigation">
            <div class="navbar-start">
                <?php if (session()->get('ldap_user')): ?>
                    <a class="navbar-item" href="<?= esc(site_url('/'), 'attr') ?>">Catalog</a>
                <?php endif ?>
            </div>
            <div class="navbar-end">
                <?php if (session()->get('ldap_user')): ?>
                    <div class="navbar-item">
                        <div id="host-stats"
                             hx-get="<?= esc(site_url('host/stats'), 'attr') ?>"
                             hx-trigger="load, every 5s"
                             hx-swap="innerHTML"
                             aria-label="Host CPU and memory usage">
                            <div class="tags has-addons mb-0">
                                <span class="tag is-dark">CPU --</span>
                                <span class="tag is-dark">RAM --</span>
                            </div>
                        </div>
                    </div>
                <?php endif ?>
                <div class="navbar-item">
                    <?php if (session()->get('ldap_user')): ?>
                        <label class="theme-control">
                            <span class="theme-switch">
                                <input class="theme-switch__input" type="checkbox" role="switch" id="theme-toggle"
                                       aria-label="Dark mode"
                                       name="dark_mode"
                                       value="1"
                                       <?= preference('dark_mode') == true ? 'checked' : '' ?>
                                       hx-post="<?= esc(site_url('settings/dark_mode'), 'attr') ?>"
                                       hx-trigger="change"
                                       hx-headers='<?= esc(json_encode([csrf_header() => csrf_hash()], JSON_THROW_ON_ERROR), 'attr') ?>'
                                       hx-on::after-request="setTimeout(() => window.location.reload(), 500)">
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
                    <?php endif ?>
                </div>
                <?php if (session()->get('ldap_user')): ?>
                    <form method="post" action="<?= esc(site_url('logout'), 'attr') ?>" class="navbar-item">
                        <?= csrf_field() ?>
                        <button type="submit" class="button is-danger is-light">Sign out</button>
                    </form>
                <?php endif ?>
            </div>
        </div>
    </div>
</nav>
