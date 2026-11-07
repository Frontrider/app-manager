<?= $this->include('partials/header') ?>
<main class="section">
    <div class="container">
        <div class="columns is-centered">
            <div class="column is-one-third-desktop is-half-tablet">
                <div class="box">
                    <h1 class="title is-4">Sign in</h1>
                    <?php if ($error !== null): ?>
                        <div class="notification is-danger is-light" role="alert"><?= esc($error) ?></div>
                    <?php endif ?>
                    <form method="post" action="<?= esc(site_url('login'), 'attr') ?>">
                        <?= csrf_field() ?>
                        <div class="field">
                            <label class="label" for="username">Username</label>
                            <input class="input" id="username" name="username" autocomplete="username" required>
                        </div>
                        <div class="field">
                            <label class="label" for="password">Password</label>
                            <input class="input" id="password" name="password" type="password" autocomplete="current-password" required>
                        </div>
                        <button class="button is-link is-fullwidth" type="submit">Sign in</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</main>
<?= $this->include('partials/footer') ?>
