<?php
$titleId = 'deploy-title-' . $app['id'];
?>
<div class="modal is-active" id="deploy-modal" role="dialog" aria-modal="true"
     aria-labelledby="<?= esc($titleId, 'attr') ?>"
     hx-on:click="if (event.target === this || event.target.closest('[data-modal-close]')) closeHtmxModal()">
    <div class="modal-background" data-modal-close></div>
    <div class="modal-card">
        <header class="modal-card-head">
            <p class="modal-card-title" id="<?= esc($titleId, 'attr') ?>">
                <?= !empty($environmentFields) ? 'Configure' : 'Deploy' ?> <?= esc($app['name']) ?>
            </p>
            <button class="delete" type="button" aria-label="Close deployment" data-modal-close></button>
        </header>
        <form action="<?= esc(site_url('apps/' . $app['id'] . '/install'), 'attr') ?>"
              method="post">
            <?= csrf_field() ?>
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
                                <p class="help" style="white-space: pre-line"><?= esc($field['description']) ?></p>
                            <?php endif ?>
                        </div>
                    <?php endforeach ?>
                <?php endif ?>
                <p class="mb-2"><strong>Applications that will be blocked</strong></p>
                <?php if ($willBlock === []): ?>
                    <p class="help">No other applications share a singleton tag with this one.</p>
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
                    <button class="button is-link" type="submit" data-modal-primary
                            aria-live="polite">
                        <?= !empty($environmentFields) ? 'Save and start' : 'Deploy' ?>
                    </button>
                    <button class="button" type="button" data-modal-close>Cancel</button>
                </div>
            </footer>
        </form>
    </div>
</div>
