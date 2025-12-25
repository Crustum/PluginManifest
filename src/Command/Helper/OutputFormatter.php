<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Command\Helper;

use Cake\Console\ConsoleIo;
use Crustum\PluginManifest\Manifest\InstallResult;

/**
 * Helper for formatting and displaying installation output
 */
class OutputFormatter
{
    /**
     * Display installation result
     *
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @param \Crustum\PluginManifest\Manifest\InstallResult $result Install result
     * @return void
     */
    public function displayResult(ConsoleIo $io, InstallResult $result): void
    {
        $source = $this->truncatePath($result->source, 60);
        $destination = $this->truncatePath($result->destination, 60);

        $statusColors = [
            'installed' => 'success',
            'batch-installed' => 'success',
            'appended' => 'success',
            'merged' => 'success',
            'would-append' => 'info',
            'would-merge' => 'info',
            'skipped' => 'warning',
            'error' => 'error',
        ];

        $color = $statusColors[$result->status] ?? 'info';

        $message = match ($result->status) {
            'installed' => "  <{$color}>[✓]</{$color}> {$source} → {$destination}",
            'batch-installed' => "  <{$color}>[✓]</{$color}> {$result->message}",
            'appended' => "  <{$color}>[✓]</{$color}> Appended to {$destination}",
            'merged' => "  <{$color}>[✓]</{$color}> Merged '{$source}' to {$destination}",
            'would-append' => "  <{$color}>[DRY]</{$color}> Would append to {$destination}",
            'would-merge' => "  <{$color}>[DRY]</{$color}> Would merge '{$source}' to {$destination}",
            'skipped' => "  <{$color}>[SKIP]</{$color}> {$destination}" .
                         ($result->message ? " ({$result->message})" : ''),
            'error' => "  <{$color}>[✗]</{$color}> {$source}: {$result->message}",
            default => "  [{$result->status}] {$source}",
        };

        $io->out($message);
    }

    /**
     * Truncate path for display
     *
     * @param string $path File path
     * @param int $maxLength Maximum length
     * @return string Truncated path
     */
    public function truncatePath(string $path, int $maxLength): string
    {
        if (strlen($path) <= $maxLength) {
            return $path;
        }

        $parts = explode(DS, $path);
        $filename = array_pop($parts);

        if (strlen($filename) >= $maxLength) {
            return '...' . substr($filename, -$maxLength + 3);
        }

        $prefix = '...';
        $availableLength = $maxLength - strlen($filename) - strlen($prefix) - 1;

        $truncated = $prefix;
        for ($i = count($parts) - 1; $i >= 0; $i--) {
            $part = $parts[$i];
            if (strlen($truncated) + strlen($part) + 1 <= $availableLength) {
                $truncated = $prefix . DS . $part . ($truncated === $prefix ? '' : DS . substr($truncated, 4));
            } else {
                break;
            }
        }

        return $truncated . DS . $filename;
    }
}
