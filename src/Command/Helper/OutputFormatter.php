<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Command\Helper;

use Cake\Console\ConsoleIo;
use Crustum\PluginManifest\Manifest\InstallResult;
use Crustum\Prompts\Console\Helper\IntroHelper;
use Crustum\Prompts\Console\Helper\OutroHelper;
use Crustum\Prompts\Console\Helper\WarningHelper;

/**
 * Helper for formatting and displaying installation output
 */
class OutputFormatter
{
    /**
     * Display dry-run banner.
     *
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @return void
     */
    public function displayDryRunNote(ConsoleIo $io): void
    {
        $warning = $io->helper('Crustum/Prompts.Warning');
        assert($warning instanceof WarningHelper);
        $warning->run([
            'message' => 'Dry run — no files will be changed',
        ]);
        $io->out('');
    }

    /**
     * Display install intro for a plugin.
     *
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @param string $pluginName Plugin name
     * @param list<string>|null $tags Tags being installed (null = all)
     * @return void
     */
    public function displayPluginIntro(ConsoleIo $io, string $pluginName, ?array $tags = null): void
    {
        $message = "Manifest install · {$pluginName}";
        if ($tags !== null && $tags !== []) {
            $message .= ' · tags: ' . implode(', ', $tags);
        }

        $intro = $io->helper('Crustum/Prompts.Intro');
        assert($intro instanceof IntroHelper);
        $intro->run(['message' => $message]);
        $io->out('');
    }

    /**
     * Display per-plugin installation outro summary.
     *
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @param string $pluginName Plugin name
     * @param int $installed Installed count
     * @param int $skipped Skipped count
     * @param int $errors Error count
     * @return void
     */
    public function displayPluginOutro(
        ConsoleIo $io,
        string $pluginName,
        int $installed,
        int $skipped,
        int $errors,
    ): void {
        $io->out('');
        $outro = $io->helper('Crustum/Prompts.Outro');
        assert($outro instanceof OutroHelper);
        $outro->run([
            'message' => "Done · {$pluginName} · {$installed} installed · {$skipped} skipped · {$errors} errors",
        ]);
    }

    /**
     * Display multi-plugin batch summary.
     *
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @param int $processed Plugins processed
     * @param int $successful Successful plugins
     * @param int $errors Plugins with errors
     * @return void
     */
    public function displayBatchSummary(ConsoleIo $io, int $processed, int $successful, int $errors): void
    {
        $io->out('');
        $outro = $io->helper('Crustum/Prompts.Outro');
        assert($outro instanceof OutroHelper);
        $outro->run([
            'message' => "Summary · {$processed} plugins · {$successful} successful · {$errors} errors",
        ]);
    }

    /**
     * Display installation result as a truncated path line.
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
        if ($path === '') {
            return '';
        }

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
