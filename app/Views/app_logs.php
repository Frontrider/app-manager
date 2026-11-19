<?= $this->include('partials/header') ?>

<main class="section">
    <div class="container">
        <a class="button is-light mb-5"
           href="<?= esc(site_url('category/' . implode('/', array_map('rawurlencode', explode(' / ', $category)))), 'attr') ?>">Back to applications</a>
        <h1 class="title is-4"><?= esc($app['name']) ?> logs</h1>
        <section>
            <p class="help mb-3">Deployment output while an app is starting, the latest startup failure, and the last 100 lines per container. Refreshes every 3 seconds. A successful start clears the saved failure.</p>
            <p class="help is-danger mb-3"
               role="status"
               hx-get="<?= esc(site_url('apps/' . $app['id'] . '/logs/output'), 'attr') ?>"
               hx-trigger="load every 3s"
               hx-target="#logs"
               hx-swap="morph"
            ></p>
            <?= view('partials/log_output', ['app' => $app, 'lines' => $logLines]) ?>
        </section>
    </div>
</main>

<?= $this->include('partials/footer') ?>
