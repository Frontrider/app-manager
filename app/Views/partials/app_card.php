<div class="column is-half-tablet is-one-third-desktop">
    <article class="card app-card">
        <img class="app-logo" src="<?= esc(site_url('apps/' . $app['id'] . '/logo'), 'attr') ?>"
             alt="<?= esc($app['name'], 'attr') ?> logo" width="320" height="160" loading="lazy">
        <div class="card-content">
            <div class="level is-mobile mb-3">
                <div class="level-left">
                    <h3 class="title is-5 mb-0"><?= esc($app['name']) ?></h3>
                </div>
                <div class="level-right">
                    <span class="tag <?= $isRunning ? 'is-success' : 'is-light' ?>">
                        <?= $isRunning ? 'Running' : 'Stopped' ?>
                    </span>
                </div>
            </div>
            <?php if (! empty($app['description'])): ?>
                <div class="content" style="overflow-wrap: anywhere"><?= (new \League\CommonMark\GithubFlavoredMarkdownConverter([
                    'html_input' => 'escape',
                    'allow_unsafe_links' => false,
                    'max_nesting_level' => 50,
                ]))->convert($app['description']) ?></div>
            <?php endif ?>
            <?php if ($isRunning && $app['caddyPath'] !== null): ?>
                <a class="button is-text mt-3" target="_blank" rel="noopener" href="<?= esc($app['caddyPath'], 'attr') ?>">Open app</a>
            <?php endif ?>
            <?php if ($app['website'] !== null): ?>
                <a class="button is-text mt-3" target="_blank" rel="noopener" href="<?= esc($app['website'], 'attr') ?>">Official website</a>
            <?php endif ?>
            <?php if (($blockedBy ?? null) !== null): ?>
                <p class="help mt-3">Stop <?= esc($blockedBy) ?> to use another app in this category.</p>
            <?php endif ?>
            <?php if ($canManage ?? false): ?>
                <a class="button is-text mt-3" target="_blank" rel="noopener" href="<?= esc(site_url('apps/' . $app['id'] . '/logs'), 'attr') ?>">View logs</a>
            <?php endif ?>
        </div>
        <?php if ($canManage ?? false): ?>
        <footer class="card-footer is-block p-4">
            <div class="is-flex mb-3">
            <?php if ($isRunning): ?>
                <form action="<?= site_url('apps/' . $app['id'] . '/uninstall') ?>" method="post" class="is-fullwidth"
                      data-pending-label="Stopping...">
                    <?= csrf_field() ?>
                    <button class="button is-danger is-light is-fullwidth" type="submit" aria-live="polite">Stop app</button>
                </form>
            <?php else: ?>
                <form action="<?= site_url('apps/' . $app['id'] . '/install') ?>" method="post" class="is-fullwidth"
                      data-pending-label="Starting...">
                    <?= csrf_field() ?>
                    <button class="button is-link is-fullwidth"
                            type="<?= ! empty($environmentFields) ? 'button' : 'submit' ?>"
                            <?= ! empty($environmentFields) ? 'data-configure-app' : '' ?> aria-live="polite"
                            <?= ($blockedBy ?? null) !== null ? 'disabled' : '' ?>>Start app</button>
                    <?php if (! empty($environmentFields)): ?>
                        <div class="modal" role="dialog" aria-modal="true" aria-hidden="true"
                             aria-labelledby="environment-title-<?= esc($app['id'], 'attr') ?>">
                            <div class="modal-background" data-cancel-configuration></div>
                            <div class="modal-card">
                            <header class="modal-card-head">
                                <p class="modal-card-title" id="environment-title-<?= esc($app['id'], 'attr') ?>">Configure <?= esc($app['name']) ?></p>
                                <button class="delete" type="button" aria-label="Close configuration" data-cancel-configuration></button>
                            </header>
                            <section class="modal-card-body">
                            <p class="mb-4">These values will be saved for future starts.</p>
                            <?php foreach ($environmentFields as $name => $field): ?>
                                <div class="field">
                                    <label class="label" for="environment-<?= esc($app['id'] . '-' . $name, 'attr') ?>"><?= esc($name) ?></label>
                                    <div class="control">
                                        <input class="input" type="<?= preg_match('/PASS|SECRET|TOKEN|KEY/i', $name) ? 'password' : 'text' ?>"
                                               id="environment-<?= esc($app['id'] . '-' . $name, 'attr') ?>"
                                               name="environment[<?= esc($name, 'attr') ?>]"
                                               value="<?= esc($field['value'], 'attr') ?>" autocomplete="off">
                                    </div>
                                    <?php if ($field['description'] !== ''): ?>
                                        <p class="help" style="white-space: pre-line"><?= esc($field['description']) ?></p>
                                    <?php endif ?>
                                </div>
                            <?php endforeach ?>
                            </section>
                            <footer class="modal-card-foot">
                            <div class="buttons">
                                <button class="button is-link" type="submit" aria-live="polite">Save and start</button>
                                <button class="button" type="button" data-cancel-configuration>Cancel</button>
                            </div>
                            </footer>
                            </div>
                        </div>
                    <?php endif ?>
                </form>
            <?php endif ?>
                <form action="<?= esc(site_url('apps/' . $app['id'] . '/refresh'), 'attr') ?>" method="post"
                      class="ml-3" data-pending-label="Refreshing...">
                    <?= csrf_field() ?>
                    <button class="button is-warning is-light is-fullwidth" type="submit" aria-live="polite"
                            <?= ($blockedBy ?? null) !== null ? 'disabled' : '' ?>>Refresh app</button>
                </form>
            </div>
        </footer>
        <?php endif ?>
    </article>
</div>
