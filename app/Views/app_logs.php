<?= $this->include('partials/header') ?>

<main class="section">
    <div class="container">
        <a class="button is-light mb-5"
           href="<?= esc(site_url('category/' . implode('/', array_map('rawurlencode', explode(' / ', $category)))), 'attr') ?>">Back to applications</a>
        <h1 class="title is-4"><?= esc($app['name']) ?> logs</h1>
        <section data-app-logs="<?= esc(site_url('apps/' . $app['id'] . '/logs/output'), 'attr') ?>">
            <p class="help mb-3">Latest startup failure and last 100 lines per container. Refreshes every 3 seconds. A successful start clears the saved failure.</p>
            <p class="help is-danger mb-3" data-log-error role="status"></p>
            <pre class="is-size-7" data-log-output tabindex="0"
                 aria-label="<?= esc($app['name'], 'attr') ?> logs"
                 style="max-height: 65vh; overflow: auto; white-space: pre-wrap; overflow-wrap: anywhere">Loading logs...</pre>
        </section>
    </div>
</main>

<?= $this->include('partials/footer') ?>
