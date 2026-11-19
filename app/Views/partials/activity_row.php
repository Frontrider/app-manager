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
$hasContainers = $application['containers'] !== [];
$formatBytes = static function (int $bytes): string {
    if ($bytes <= 0) {
        return '0 B';
    }
    $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
    $power = min((int) floor(log($bytes, 1024)), count($units) - 1);

    return number_format($bytes / (1024 ** $power), $power === 0 ? 0 : 1) . ' ' . $units[$power];
};
$cpuUsage = $hasContainers ? number_format((float) ($application['cpuPercent'] ?? 0), 1) . '%' : '—';
$memoryUsage = $hasContainers ? $formatBytes((int) ($application['memoryBytes'] ?? 0)) : '—';
?>
<tr id="activity-<?= esc($application['id'], 'attr') ?>" data-activity-row>
    <td><?= esc($application['name']) ?></td>
    <td><?= esc($application['category']) ?></td>
    <td><span class="tag <?= esc($statusClass, 'attr') ?>"><?= esc($application['status']) ?></span></td>
    <td data-activity-cpu><?= esc($cpuUsage) ?></td>
    <td data-activity-memory><?= esc($memoryUsage) ?></td>
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
