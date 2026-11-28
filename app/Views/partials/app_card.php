<div class="column is-half-tablet is-one-third-desktop">
    <article class="card app-card">
        <img class="app-logo" src="<?= esc(site_url('apps/' . $app['id'] . '/logo'), 'attr') ?>"
             alt="<?= esc($app['name'], 'attr') ?> logo" width="320" height="160" loading="lazy">
        <div class="card-content">
            <div class="is-mobile mb-3">
                <h3 class="title is-5 mb-0"><?= esc($app['name']) ?></h3>
                <br/>
                <div class="tags has-addons mb-0">
                        <span class="tag <?= $isRunning ? 'is-success' : 'is-light' ?>">
                            <?= $isRunning ? 'Running' : 'Stopped' ?>
                        </span>
                    <?php if ($app['recommended'] ?? false): ?>
                        <span title="Recommended application"
                              class="tag is-info is-light">Recommended</span>
                    <?php endif ?>
                    <?php if ($app['protected'] ?? false): ?>
                        <span title="Will not shut down if the server is overloaded"
                              class="tag is-success is-light">Protected</span>
                    <?php endif ?>
                </div>
            </div>
            <?php if (!empty($app['description'])): ?>
                <div class="content"
                     style="overflow-wrap: anywhere"><?= (new \League\CommonMark\GithubFlavoredMarkdownConverter([
                        'html_input' => 'escape',
                        'allow_unsafe_links' => false,
                        'max_nesting_level' => 50,
                    ]))->convert($app['description']) ?></div>
            <?php endif ?>
            <?php
            $hasLinks = ($isRunning && $app['caddyPath'] !== null)
                || $app['website'] !== null;
            $hasMeta = $hasLinks || ($blockedBy ?? null) !== null;
            ?>
            <?php if ($hasMeta): ?>
                <div class="app-card-meta">
                    <?php if (($blockedBy ?? null) !== null): ?>
                        <p class="help mb-0">Deployment blocked by <strong><?= esc($blockedBy) ?>.</strong></p>
                    <?php endif ?>
                    <?php if ($hasLinks): ?>
                        <div class="app-card-links">
                            <?php if ($isRunning && $app['caddyPath'] !== null): ?>
                                <a class="button is-text" target="_blank" rel="noopener"
                                   href="<?= esc($app['caddyPath'], 'attr') ?>">Open page</a>
                            <?php endif ?>
                            <?php if ($app['website'] !== null): ?>
                                <a class="button is-text" target="_blank" rel="noopener"
                                   href="<?= esc($app['website'], 'attr') ?>">Official website</a>
                            <?php endif ?>
                        </div>
                    <?php endif ?>
                </div>
            <?php endif ?>
        </div>
        <?php if ($canManage ?? false): ?>
            <footer class="card-footer is-block p-4">
                <div class="is-flex mb-3">
                    <?php if ($isRunning): ?>
                        <form action="<?= site_url('apps/' . $app['id'] . '/uninstall') ?>" method="post"
                              class="is-fullwidth">
                            <?= csrf_field() ?>
                            <button class="button is-danger is-light is-fullwidth" type="submit" aria-live="polite">Stop
                                service
                            </button>
                        </form>
                    <?php else: ?>
                        <?php $blocked = ($blockedBy ?? null) !== null; ?>
                        <?php $showDeployDialog = ! empty($environmentFields) || ! empty($app['singletonTags'] ?? []); ?>
                        <?php if ($showDeployDialog): ?>
                            <button class="button is-link is-fullwidth"
                                    type="button"
                                    hx-get="<?= esc(site_url('apps/' . $app['id'] . '/configure'), 'attr') ?>"
                                    hx-target="#modal-container"
                                    hx-swap="innerHTML"
                                    hx-indicator="#modal-loading"
                                    hx-disabled-elt="this"
                                    aria-live="polite"
                                <?= $blocked ? 'disabled' : '' ?>>Start service
                            </button>
                        <?php else: ?>
                            <form action="<?= esc(site_url('apps/' . $app['id'] . '/install'), 'attr') ?>" method="post"
                                  class="is-fullwidth">
                                <?= csrf_field() ?>
                                <button class="button is-link is-fullwidth" type="submit" aria-live="polite"
                                    <?= $blocked ? 'disabled' : '' ?>>Start service
                                </button>
                            </form>
                        <?php endif ?>
                    <?php endif ?>
                    <a class="button is-light ml-3"
                       href="<?= esc(site_url('apps/' . $app['id'] . '/logs'), 'attr') ?>">Logs</a>
                </div>
            </footer>
        <?php endif ?>
    </article>
</div>
