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
            <?php if ($isRunning && $app['caddyPath'] !== null): ?>
                <a class="button is-text mt-3" target="_blank" rel="noopener"
                   href="<?= esc($app['caddyPath'], 'attr') ?>">Open app</a>
            <?php endif ?>
            <?php if ($app['website'] !== null): ?>
                <a class="button is-text mt-3" target="_blank" rel="noopener"
                   href="<?= esc($app['website'], 'attr') ?>">Official website</a>
            <?php endif ?>
            <?php if ($canManage ?? false): ?>
                <a class="button is-text mt-3" target="_blank" rel="noopener"
                   href="<?= esc(site_url('apps/' . $app['id'] . '/logs'), 'attr') ?>">View logs</a>
            <?php endif ?>
            <?php if (($blockedBy ?? null) !== null): ?>
                <p class="help mt-3">Deployment blocked by <strong><?= esc($blockedBy) ?>.</strong></p>
            <?php endif ?>
        </div>
        <?php if ($canManage ?? false): ?>
            <footer class="card-footer is-block p-4">
                <div class="is-flex mb-3">
                    <?php if ($isRunning): ?>
                        <form action="<?= site_url('apps/' . $app['id'] . '/uninstall') ?>" method="post"
                              class="is-fullwidth"
                              data-pending-label="Stopping...">
                            <?= csrf_field() ?>
                            <button class="button is-danger is-light is-fullwidth" type="submit" aria-live="polite">Stop
                                app
                            </button>
                        </form>
                    <?php else: ?>
                        <?php $blocked = ($blockedBy ?? null) !== null; ?>
                        <?php $willBlock = array_values($wouldBlock ?? []); ?>
                        <?php $showDeployDialog = ! empty($environmentFields) || ! empty($app['singletonTags'] ?? []); ?>
                        <form action="<?= esc(site_url('apps/' . $app['id'] . '/install'), 'attr') ?>" method="post"
                              class="is-fullwidth"
                              data-pending-label="Starting...">
                            <?= csrf_field() ?>
                            <button class="button is-link is-fullwidth"
                                    type="<?= $showDeployDialog ? 'button' : 'submit' ?>"
                                <?= $showDeployDialog ? 'data-deploy-app' : '' ?>
                                <?= !empty($environmentFields) ? 'data-configure-app' : '' ?> aria-live="polite"
                                <?= $blocked ? 'disabled' : '' ?>>Start app
                            </button>
                            <?php if ($showDeployDialog): ?>
                            <div class="modal" role="dialog" aria-modal="true" aria-hidden="true"
                                 aria-labelledby="deploy-title-<?= esc($app['id'], 'attr') ?>">
                                <div class="modal-background" data-cancel-configuration></div>
                                <div class="modal-card">
                                    <header class="modal-card-head">
                                        <p class="modal-card-title" id="deploy-title-<?= esc($app['id'], 'attr') ?>">
                                            <?= !empty($environmentFields) ? 'Configure' : 'Deploy' ?> <?= esc($app['name']) ?>
                                        </p>
                                        <button class="delete" type="button" aria-label="Close deployment"
                                                data-cancel-configuration></button>
                                    </header>
                                    <section class="modal-card-body">
                                        <?php if (!empty($environmentFields)): ?>
                                            <p class="mb-4">These values will be saved for future starts.</p>
                                            <?php foreach ($environmentFields as $name => $field): ?>
                                                <div class="field">
                                                    <label class="label"
                                                           for="environment-<?= esc($app['id'] . '-' . $name, 'attr') ?>"><?= esc($name) ?></label>
                                                    <div class="control">
                                                        <input class="input"
                                                               type="<?= preg_match('/PASS|SECRET|TOKEN|KEY/i', $name) ? 'password' : 'text' ?>"
                                                               id="environment-<?= esc($app['id'] . '-' . $name, 'attr') ?>"
                                                               name="environment[<?= esc($name, 'attr') ?>]"
                                                               value="<?= esc($field['value'], 'attr') ?>"
                                                               autocomplete="off">
                                                    </div>
                                                    <?php if ($field['description'] !== ''): ?>
                                                        <p class="help"
                                                           style="white-space: pre-line"><?= esc($field['description']) ?></p>
                                                    <?php endif ?>
                                                </div>
                                            <?php endforeach ?>
                                        <?php endif ?>
                                        <p class="mb-2"><strong>Applications that will be blocked</strong></p>
                                        <?php if ($willBlock === []): ?>
                                            <p class="help">No other applications share a singleton tag with this
                                                one.</p>
                                        <?php else: ?>
                                            <ul>
                                                <?php foreach ($willBlock as $blockedName): ?>
                                                    <li><?= esc($blockedName) ?></li>
                                                <?php endforeach ?>
                                            </ul>
                                        <?php endif ?>
                                    </section>
                                    <footer class="modal-card-foot">
                                        <div class="buttons">
                                            <button class="button is-link" type="submit" data-deploy-confirm
                                                    aria-live="polite">
                                                <?= !empty($environmentFields) ? 'Save and start' : 'Deploy' ?>
                                            </button>
                                            <button class="button" type="button" data-cancel-configuration>Cancel
                                            </button>
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
                            <?= ($blockedBy ?? null) !== null ? 'disabled' : '' ?>>Refresh app
                        </button>
                    </form>
                </div>
            </footer>
        <?php endif ?>
    </article>
</div>
