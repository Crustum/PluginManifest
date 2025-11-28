<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Manifest;

use RuntimeException;

/**
 * Service for merging configuration into app config files
 *
 * This service merges configuration while preserving user comments and file structure.
 * It inserts new config entries before the return statement, maintaining all existing content.
 */
class ConfigMerger
{
    /**
     * Maximum allowed config file size (1MB)
     */
    protected const MAX_CONFIG_SIZE = 1048576;

    /**
     * Merge configuration into config file
     *
     * Preserves file structure and comments by inserting new config entries
     * before the return statement. Never overwrites existing keys.
     *
     * @param string $filePath Path to config file
     * @param string $key Configuration key
     * @param array<string, mixed> $value Configuration value
     * @param bool $dryRun Whether to perform dry run (no actual changes)
     * @return \Crustum\PluginManifest\Manifest\InstallResult
     */
    public function merge(
        string $filePath,
        string $key,
        array $value,
        bool $dryRun = false,
    ): InstallResult {
        if (!file_exists($filePath)) {
            return new InstallResult(false, $key, $filePath, 'error', "File does not exist: {$filePath}");
        }

        if (filesize($filePath) > static::MAX_CONFIG_SIZE) {
            return new InstallResult(false, $key, $filePath, 'error', 'Config file too large (>1MB), manual review required');
        }

        $config = require $filePath;

        if (!is_array($config)) {
            return new InstallResult(false, $key, $filePath, 'error', 'Config file does not return array');
        }

        if (isset($config[$key])) {
            return new InstallResult(false, $key, $filePath, 'skipped', "Key '{$key}' already exists in config");
        }

        $config[$key] = $value;

        if (!$dryRun) {
            $this->writeConfigFile($filePath, $config);
        }

        return new InstallResult(true, $key, $filePath, $dryRun ? 'would-merge' : 'merged');
    }

    /**
     * Write config file while preserving structure and comments
     *
     * Finds the return statement and inserts new config entries before it.
     *
     * @param string $filePath Path to config file
     * @param array<string, mixed> $config Configuration array
     * @return void
     * @throws \RuntimeException
     */
    protected function writeConfigFile(string $filePath, array $config): void
    {
        $originalContent = file_get_contents($filePath);
        if ($originalContent === false) {
            throw new RuntimeException("Could not read config file: {$filePath}");
        }

        $lines = explode("\n", $originalContent);

        $closingBracketIndex = null;
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $trimmed = trim($lines[$i]);
            if ($trimmed === '];' || $trimmed === ']') {
                $closingBracketIndex = $i;
                break;
            }
        }

        if ($closingBracketIndex === null) {
            throw new RuntimeException("Could not find closing bracket in config file: {$filePath}");
        }

        $newConfigLines = [];
        foreach ($config as $key => $value) {
            $valueExport = var_export($value, true);
            $valueExport = (string)preg_replace('/array \(/', '[', $valueExport);
            $valueExport = (string)preg_replace('/\)$/', ']', $valueExport);
            $valueExport = (string)preg_replace('/\),/', '],', $valueExport);

            $exportLines = explode("\n", $valueExport);
            $lineCount = count($exportLines);

            if ($lineCount === 1) {
                $newConfigLines[] = "    '{$key}' => {$valueExport},";
            } else {
                $newConfigLines[] = "    '{$key}' => " . $exportLines[0];
                for ($i = 1; $i < $lineCount; $i++) {
                    $line = $exportLines[$i];
                    $newConfigLines[] = '    ' . $line . ($i === $lineCount - 1 ? ',' : '');
                }
            }
        }

        array_splice($lines, $closingBracketIndex, 0, $newConfigLines);

        file_put_contents($filePath, implode("\n", $lines));
    }
}
