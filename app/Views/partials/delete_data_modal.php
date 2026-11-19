<?php
$titleId = 'delete-data-title-' . $app['id'];
?>
<div class="modal is-active" id="delete-data-modal" role="dialog" aria-modal="true"
     aria-labelledby="<?= esc($titleId, 'attr') ?>"
     hx-on:click="if (event.target === this || event.target.closest('[data-modal-close]')) closeHtmxModal()">
    <div class="modal-background" data-modal-close></div>
    <div class="modal-card">
        <header class="modal-card-head">
            <p class="modal-card-title" id="<?= esc($titleId, 'attr') ?>">
                Delete <?= esc($app['name']) ?> data?
            </p>
            <button class="delete" type="button" aria-label="Close confirmation" data-modal-close></button>
        </header>
        <form action="<?= esc(site_url('apps/' . $app['id'] . '/delete-data'), 'attr') ?>"
              method="post">
            <?= csrf_field() ?>
            <section class="modal-card-body">
                <p class="mb-3">This permanently deletes this application’s Docker Compose volumes and all data
                    stored in them. This action is destructive and cannot be undone.</p>
                <p>External volumes and mounted host data are not removed.</p>
            </section>
            <footer class="modal-card-foot">
                <div class="buttons">
                    <button class="button is-danger" type="submit" data-modal-primary aria-live="polite">
                        Delete volumes permanently
                    </button>
                    <button class="button" type="button" data-modal-close>Cancel</button>
                </div>
            </footer>
        </form>
    </div>
</div>
