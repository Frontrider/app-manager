<?php

use App\Libraries\HostStats;
use CodeIgniter\Test\CIUnitTestCase;

final class HostStatsTest extends CIUnitTestCase
{
    public function testSnapshotReportsHostCpuAndMemoryUsage(): void
    {
        $stats = (new HostStats())->snapshot();

        $this->assertGreaterThanOrEqual(0, $stats['cpuPercent']);
        $this->assertLessThanOrEqual(100, $stats['cpuPercent']);
        $this->assertGreaterThanOrEqual(0, $stats['memoryPercent']);
        $this->assertLessThanOrEqual(100, $stats['memoryPercent']);
        $this->assertGreaterThan(0, $stats['memoryTotalBytes']);
        $this->assertGreaterThanOrEqual(0, $stats['memoryUsedBytes']);
        $this->assertLessThanOrEqual($stats['memoryTotalBytes'], $stats['memoryUsedBytes']);
    }
}
