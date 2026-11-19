<?= $this->include('partials/header') ?>

<?php
$hasRows = $applications !== [];
$emptyHidden = $hasRows || $error !== null;
$errorHidden = $error === null;
?>
<main class="section">
    <div class="container"
         data-activity-panel
         hx-get="<?= esc(site_url('activity/output'), 'attr') ?>"
         hx-trigger="every 3s [!document.querySelector('.modal.is-active')]"
         hx-target="#activity-rows"
         hx-swap="morph"
         hx-on::before-swap="if (document.querySelector('.modal.is-active')) { event.detail.shouldSwap = false; } else if (event.detail.xhr.status >= 500) { event.detail.shouldSwap = true; }">
        <h1 class="title is-4">Application activity</h1>
        <p class="mb-4">Applications with running or stopped services, plus operations in progress. Refreshes every 3 seconds.</p>
        <p id="activity-error" class="notification is-danger is-light<?= $errorHidden ? ' is-hidden' : '' ?>"
           data-activity-error role="alert"><?= esc($error ?? '') ?></p>
        <p id="activity-empty" class="notification is-light<?= $emptyHidden ? ' is-hidden' : '' ?>"
           data-activity-empty role="status">No application containers or operations to show.</p>
        <div class="table-container">
            <table class="table is-fullwidth is-striped">
                <caption class="is-sr-only">Current application and container states</caption>
                <thead><tr><th>Application</th><th>Category</th><th>Status</th><th>CPU</th><th>Memory</th><th>Containers</th><?php if ($canManage): ?><th>Logs</th><?php endif ?></tr></thead>
                <tbody id="activity-rows" data-activity-rows><?php foreach ($applications as $application): ?><?= view('partials/activity_row', ['application' => $application, 'canManage' => $canManage]) ?><?php endforeach ?></tbody>
            </table>
        </div>
    </div>
</main>

<?= $this->include('partials/footer') ?>
