<?php if ($stats === null): ?>
    <div class="tags has-addons mb-0">
        <span class="tag is-dark">Host stats unavailable</span>
    </div>
<?php else: ?>
    <?php
    $cpuPercent = (int) round($stats['cpuPercent']);
    $memoryPercent = (int) round($stats['memoryPercent']);
    $usedGiB = number_format($stats['memoryUsedBytes'] / 1024 / 1024 / 1024, 1);
    $totalGiB = number_format($stats['memoryTotalBytes'] / 1024 / 1024 / 1024, 1);
    ?>
    <div class="tags has-addons mb-0">
        <span class="tag is-dark" title="Host CPU usage">CPU
            <span class="ml-1 has-text-weight-semibold"><?= $cpuPercent ?>%</span>
        </span>
        <span class="tag is-dark"
              title="Host memory usage: <?= $usedGiB ?> GiB of <?= $totalGiB ?> GiB">RAM
            <span class="ml-1 has-text-weight-semibold"><?= $memoryPercent ?>%</span>
        </span>
    </div>
<?php endif ?>
