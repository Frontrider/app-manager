<?php

namespace App\Libraries;

use RuntimeException;

class LoggingModel
{
    /**
     * Split log output into morphable line elements. The element ID is derived
     * from the line content so morphing keeps unchanged lines and only updates
     * the lines that actually changed.
     *
     * @return list<array{id: string, text: string}>
     */
    public function logLines(string $logs): array
    {
        preg_match_all('/[^\n]*\n|[^\n]+$/', $logs, $matches);
        $lines = [];
        $occurrences = [];
        foreach ($matches[0] as $text) {
            $hash = substr(hash('xxh3', $text), 0, 16);
            $occurrence = ($occurrences[$hash] ?? 0) + 1;
            $occurrences[$hash] = $occurrence;
            $lines[] = [
                'id' => 'log-' . $hash . '-' . $occurrence,
                'text' => $text,
            ];
        }

        return $lines;
    }

    public function plainTextLogs(string $logs): string
    {
        // Remove terminal strings, CSI commands, and two-byte escape commands, not Unicode bytes.
        $logs = preg_replace(
            '/\x1B(?:\][^\x07\x1B]*(?:\x07|\x1B\\\\)|[PX^_][\s\S]*?\x1B\\\\|\[[0-?]*[ -\/]*[@-~]|[ -\/]*[0-~])/',
            '',
            $logs,
        );
        if ($logs === null) {
            throw new RuntimeException('Could not normalize terminal log output.');
        }

        return str_replace(["\r\n", "\r"], "\n", $logs);
    }
}
