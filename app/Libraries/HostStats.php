<?php

namespace App\Libraries;

use RuntimeException;

class HostStats
{
    /**
     * @return array{cpuPercent: float, memoryPercent: float, memoryUsedBytes: int, memoryTotalBytes: int}
     */
    public function snapshot(): array
    {
        $before = $this->cpuSample();
        usleep(150000);
        $after = $this->cpuSample();

        $totalDelta = $after['total'] - $before['total'];
        $idleDelta = $after['idle'] - $before['idle'];
        $cpuPercent = $totalDelta > 0 ? (1 - ($idleDelta / $totalDelta)) * 100 : 0.0;

        $memory = $this->memorySample();

        return [
            'cpuPercent' => round(max(0, min(100, $cpuPercent)), 1),
            'memoryPercent' => round($memory['percent'], 1),
            'memoryUsedBytes' => $memory['used'],
            'memoryTotalBytes' => $memory['total'],
        ];
    }

    /**
     * @return array{total: int, idle: int}
     */
    private function cpuSample(): array
    {
        $contents = $this->read('/proc/stat');
        if (! preg_match('/^cpu\s+(.+)$/m', $contents, $match)) {
            throw new RuntimeException('Could not read host CPU statistics.');
        }

        $values = array_map('intval', preg_split('/\s+/', trim($match[1])) ?: []);
        if (count($values) < 4) {
            throw new RuntimeException('Could not parse host CPU statistics.');
        }

        return [
            'total' => array_sum(array_slice($values, 0, 8)),
            'idle' => $values[3] + ($values[4] ?? 0),
        ];
    }

    /**
     * @return array{total: int, used: int, percent: float}
     */
    private function memorySample(): array
    {
        $contents = $this->read('/proc/meminfo');
        if (! preg_match('/^MemTotal:\s+(\d+)\s+kB$/m', $contents, $total)
            || ! preg_match('/^MemAvailable:\s+(\d+)\s+kB$/m', $contents, $available)) {
            throw new RuntimeException('Could not read host memory statistics.');
        }

        $totalBytes = (int) $total[1] * 1024;
        $availableBytes = (int) $available[1] * 1024;
        $usedBytes = max(0, $totalBytes - $availableBytes);

        return [
            'total' => $totalBytes,
            'used' => $usedBytes,
            'percent' => $totalBytes > 0 ? ($usedBytes / $totalBytes) * 100 : 0.0,
        ];
    }

    private function read(string $path): string
    {
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Could not read host statistics.');
        }

        return $contents;
    }
}
