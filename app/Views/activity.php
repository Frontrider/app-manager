<?= $this->include('partials/header') ?>

<main class="section">
    <div class="container" data-application-activity="<?= esc(site_url('activity/output'), 'attr') ?>">
        <h1 class="title is-4">Application activity</h1>
        <p class="mb-4">Running applications and operations in progress. Refreshes every 3 seconds.</p>
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
                           href="<?= esc(site_url('apps'), 'attr') ?>">Logs</a></td>
                <?php endif ?>
            </tr>
        </template>
    </div>
</main>

<?= $this->include('partials/footer') ?>
