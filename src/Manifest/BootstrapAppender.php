<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Manifest;

/**
 * Service for appending code to bootstrap files
 *
 * This service appends code to bootstrap files (bootstrap.php, bootstrap_after.php,
 * plugin_bootstrap_after.php) with safety checks to prevent duplicates and overloading.
 */
class BootstrapAppender
{
    /**
     * Maximum allowed bootstrap file size (1MB)
     */
    protected const MAX_BOOTSTRAP_SIZE = 1048576;

    /**
     * Append content to bootstrap file
     *
     * Checks for duplicates using marker and content comparison.
     * Validates file size to prevent overloading.
     *
     * @param string $filePath Path to bootstrap file
     * @param string $content Content to append
     * @param string|null $marker Marker comment for duplicate detection
     * @param bool $dryRun Whether to perform dry run (no actual changes)
     * @return \Crustum\PluginManifest\Manifest\InstallResult
     */
    public function append(
        string $filePath,
        string $content,
        ?string $marker = null,
        bool $dryRun = false,
    ): InstallResult {
        if (!file_exists($filePath)) {
            return new InstallResult(false, $content, $filePath, 'error', "File does not exist: {$filePath}");
        }

        if (filesize($filePath) > static::MAX_BOOTSTRAP_SIZE) {
            return new InstallResult(false, $content, $filePath, 'error', 'Bootstrap file too large (>1MB), manual review required');
        }

        $existingContent = file_get_contents($filePath);
        if ($existingContent === false) {
            return new InstallResult(false, $content, $filePath, 'error', "Could not read file: {$filePath}");
        }

        if ($marker && strpos($existingContent, $marker) !== false) {
            return new InstallResult(false, $content, $filePath, 'skipped', 'Marker already exists in file');
        }

        if (strpos($existingContent, trim($content)) !== false) {
            return new InstallResult(false, $content, $filePath, 'skipped', 'Content already exists in file');
        }

        $newContent = rtrim($existingContent) . "\n\n";
        if ($marker) {
            $newContent .= $marker . "\n";
        }
        $newContent .= $content . "\n";

        if (!$dryRun) {
            file_put_contents($filePath, $newContent);
        }

        return new InstallResult(true, $content, $filePath, $dryRun ? 'would-append' : 'appended');
    }
}
