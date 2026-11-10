<?= $this->include('partials/header') ?>

<main class="section">
    <div class="container" data-application-activity="<?= esc(site_url('activity/output'), 'attr') ?>">
        <h1 class="title is-4">Application activity</h1>
        <p class="mb-4">Applications with running or stopped services, plus operations in progress. Refreshes every 3 seconds.</p>
        <p class="notification is-danger is-light is-hidden" data-activity-error role="alert"></p>
        <p class="notification is-light" data-activity-empty role="status">Loading application activity...</p>
        <div class="table-container">
            <table class="table is-fullwidth is-striped">
                <caption class="is-sr-only">Current application and container states</caption>
                <thead><tr><th>Application</th><th>Category</th><th>Status</th><th>Containers</th><?php if ($canManage): ?><th>Logs</th><?php endif ?></tr></thead>
                <tbody data-activity-rows></tbody>
            </table>
        </div>
        <template data-activity-row>
            <tr>
                <td data-activity-name></td>
                <td data-activity-category></td>
                <td><span class="tag" data-activity-status></span></td>
                <td><ul data-activity-containers></ul></td>
                <?php if ($canManage): ?>
                    <td><a class="button is-small is-light" data-activity-logs
                           href="<?= esc(site_url('apps'), 'attr') ?>">Logs</a>
                        <form class="is-inline-block" method="post" data-delete-data-form
                              data-pending-label="Deleting..." action="<?= esc(site_url('apps'), 'attr') ?>">
                            <?= csrf_field() ?>
                            <button class="button is-small is-danger is-light" type="button" data-configure-app>
                                Delete data
                            </button>
                            <div class="modal" role="dialog" aria-modal="true" aria-hidden="true"
                                 aria-labelledby="delete-data-title">
                                <div class="modal-background" data-cancel-configuration></div>
                                <div class="modal-card">
                                    <header class="modal-card-head">
                                        <p class="modal-card-title" data-delete-title>Delete application data?</p>
                                        <button class="delete" type="button" aria-label="Close confirmation"
                                                data-cancel-configuration></button>
                                    </header>
                                    <section class="modal-card-body">
                                        <p class="mb-3">This permanently deletes this application’s Docker Compose volumes and all data stored in them. This action is destructive and cannot be undone.</p>
                                        <p>External volumes and bind-mounted host data are not removed.</p>
                                    </section>
                                    <footer class="modal-card-foot">
                                        <div class="buttons">
                                            <button class="button is-danger" type="submit" data-confirm-delete>
                                                Delete volumes permanently
                                            </button>
                                            <button class="button" type="button" data-cancel-configuration>Cancel</button>
                                        </div>
                                    </footer>
                                </div>
                            </div>
                        </form>
                    </td>
                <?php endif ?>
            </tr>
        </template>
    </div>
</main>

<?= $this->include('partials/footer') ?>
