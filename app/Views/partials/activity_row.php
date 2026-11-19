<?php
$statusClasses = [
    'Running' => 'is-success',
    'Starting' => 'is-info',
    'Stopping' => 'is-warning',
    'Stopped' => 'is-dark',
    'Deleting' => 'is-danger',
    'Failed' => 'is-danger',
    'Paused' => 'is-warning',
];
$statusClass = $statusClasses[$application['status']] ?? 'is-light';
?>
<tr id="activity-<?= esc($application['id'], 'attr') ?>" data-activity-row>
    <td><?= esc($application['name']) ?></td>
    <td><?= esc($application['category']) ?></td>
    <td><span class="tag <?= esc($statusClass, 'attr') ?>"><?= esc($application['status']) ?></span></td>
    <td>
        <?php if ($application['containers'] === []): ?>
            No active containers yet.
        <?php else: ?>
            <ul>
                <?php foreach ($application['containers'] as $container): ?>
                    <li><?= esc($container['name']) ?>: <?= esc($container['status']) ?></li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    </td>
    <?php if ($canManage): ?>
        <td>
            <a class="button is-small is-light" data-activity-logs
               href="<?= esc(site_url('apps/' . $application['id'] . '/logs'), 'attr') ?>">Logs</a>
            <button class="button is-small is-danger is-light" type="button"
                    hx-get="<?= esc(site_url('apps/' . $application['id'] . '/delete-data'), 'attr') ?>"
                    hx-target="#modal-container"
                    hx-swap="innerHTML"
                    hx-indicator="#modal-loading"
                    hx-disabled-elt="this"
                    <?= $application['status'] === 'Stopped' ? '' : 'hidden' ?>>
                Delete data
            </button>
        </td>
    <?php endif ?>
</tr>
