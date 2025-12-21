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
     * Supports dot-notation paths for nested keys:
     * - 'Notification' - top-level key
     * - 'Notification.channels' - nested key
     * - 'Notification.channels.slack' - deeply nested key
     *
     * @param string $filePath Path to config file
     * @param string $key Configuration key (supports dot-notation for nested paths)
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

        $path = explode('.', $key);
        $targetKey = end($path);
        $parentPath = array_slice($path, 0, -1);

        if (empty($parentPath)) {
            if (isset($config[$targetKey])) {
                return new InstallResult(false, $key, $filePath, 'skipped', "Key '{$key}' already exists in config");
            }

            $config[$targetKey] = $value;

            if (!$dryRun) {
                $this->writeConfigFile($filePath, $config);
            }
        } else {
            $parentConfig = $config;
            foreach ($parentPath as $p) {
                if (!isset($parentConfig[$p]) || !is_array($parentConfig[$p])) {
                    return new InstallResult(false, $key, $filePath, 'error', "Parent path '" . implode('.', $parentPath) . "' does not exist or is not an array");
                }
                $parentConfig = $parentConfig[$p];
            }

            if (isset($parentConfig[$targetKey])) {
                return new InstallResult(false, $key, $filePath, 'skipped', "Key '{$key}' already exists in config");
            }

            if (!$dryRun) {
                $this->writeConfigFileWithPath($filePath, $path, $value);
            }
        }

        return new InstallResult(true, $key, $filePath, $dryRun ? 'would-merge' : 'merged');
    }

    /**
     * Write config file while preserving structure and comments
     *
     * Finds the return statement and inserts new config entries before it.
     * Used for top-level keys only.
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
            $valueExport = $this->exportValue($value);
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

    /**
     * Write config file with nested path support
     *
     * Finds the insertion point for a nested key path and inserts the value.
     *
     * @param string $filePath Path to config file
     * @param array<string> $path Key path (e.g., ['Notification', 'channels', 'slack'])
     * @param array<string, mixed> $value Value to insert
     * @return void
     * @throws \RuntimeException
     */
    protected function writeConfigFileWithPath(
        string $filePath,
        array $path,
        array $value,
    ): void {
        $originalContent = file_get_contents($filePath);
        if ($originalContent === false) {
            throw new RuntimeException("Could not read config file: {$filePath}");
        }

        $lines = explode("\n", $originalContent);
        $targetKey = (string)end($path);
        $parentPath = array_slice($path, 0, -1);

        $insertionPoint = $this->findInsertionPointForPath($lines, $parentPath);

        if ($insertionPoint !== null) {
            $newLines = $this->formatKeyValue($targetKey, $value, $insertionPoint['indent']);
            array_splice($lines, $insertionPoint['line'], 0, $newLines);
        } else {
            $closingBracketIndex = $this->findTopLevelClosingBracket($lines);
            if ($closingBracketIndex !== null) {
                $newLines = $this->formatKeyValue($targetKey, $value, 4);
                array_splice($lines, $closingBracketIndex, 0, $newLines);
            } else {
                throw new RuntimeException('Could not find insertion point for path: ' . implode('.', $path));
            }
        }

        file_put_contents($filePath, implode("\n", $lines));
    }

    /**
     * Export a value to PHP code format
     *
     * Handles RawValue objects by outputting their code as-is.
     * For arrays, recursively processes nested values.
     *
     * @param mixed $value Value to export
     * @return string PHP code representation
     */
    protected function exportValue(mixed $value): string
    {
        if ($value instanceof RawValue) {
            return $value->code;
        }

        if (is_array($value)) {
            return $this->exportArray($value);
        }

        $valueExport = var_export($value, true);
        $valueExport = (string)preg_replace('/array \(/', '[', $valueExport);
        $valueExport = (string)preg_replace('/\)$/', ']', $valueExport);
        $valueExport = (string)preg_replace('/\),/', '],', $valueExport);

        return $valueExport;
    }

    /**
     * Export an array to PHP code format
     *
     * Recursively processes array values, handling RawValue objects.
     *
     * @param array<string, mixed> $array Array to export
     * @return string PHP code representation
     */
    protected function exportArray(array $array): string
    {
        if (empty($array)) {
            return '[]';
        }

        $lines = ['['];
        foreach ($array as $key => $value) {
            if ($value instanceof RawValue) {
                $lines[] = "    '{$key}' => {$value->code},";
            } elseif (is_array($value)) {
                $nested = $this->exportArray($value);
                $nestedLines = explode("\n", $nested);
                $nestedLinesCount = count($nestedLines);
                $lines[] = "    '{$key}' => " . $nestedLines[0];
                for ($i = 1; $i < $nestedLinesCount; $i++) {
                    $lines[] = '    ' . $nestedLines[$i];
                }
                $lines[count($lines) - 1] .= ',';
            } else {
                $valueExport = var_export($value, true);
                $lines[] = "    '{$key}' => {$valueExport},";
            }
        }
        $lines[] = ']';

        return implode("\n", $lines);
    }

    /**
     * Find insertion point for a nested key path
     *
     * @param array<int, string> $lines File lines
     * @param array<string> $parentPath Parent path (e.g., ['Notification', 'channels'])
     * @return array<string, mixed>|null Array with 'line' and 'indent' keys
     */
    protected function findInsertionPointForPath(
        array $lines,
        array $parentPath,
    ): ?array {
        if (empty($parentPath)) {
            return null;
        }

        $parentKey = end($parentPath);
        $keyPattern = "/['\"]" . preg_quote($parentKey, '/') . "['\"]\s*=>/";
        $inArray = false;
        $arrayDepth = 0;
        $targetLine = null;
        $indent = 4;
        $linesCount = count($lines);

        for ($i = 0; $i < $linesCount; $i++) {
            $line = $lines[$i];
            $trimmed = trim($line);

            if (preg_match($keyPattern, $line)) {
                $inArray = true;
                $indent = strlen($line) - strlen(ltrim($line));
                continue;
            }

            if ($inArray) {
                if (str_contains($trimmed, '[') && !str_contains($trimmed, ']')) {
                    $arrayDepth++;
                }
                if (str_contains($trimmed, ']') && !str_contains($trimmed, '[')) {
                    if ($arrayDepth === 0) {
                        $targetLine = $i;
                        break;
                    }
                    $arrayDepth--;
                }
            }
        }

        if ($targetLine !== null) {
            return ['line' => $targetLine, 'indent' => $indent + 4];
        }

        return null;
    }

    /**
     * Find top-level closing bracket
     *
     * @param array<int, string> $lines File lines
     * @return int|null Line index of closing bracket
     */
    protected function findTopLevelClosingBracket(array $lines): ?int
    {
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $trimmed = trim($lines[$i]);
            if ($trimmed === '];' || $trimmed === ']') {
                return $i;
            }
        }

        return null;
    }

    /**
     * Format a key-value pair for insertion
     *
     * @param string $key Key name
     * @param mixed $value Value to format
     * @param int $indent Indentation level in spaces
     * @return array<int, string> Array of formatted lines
     */
    protected function formatKeyValue(string $key, mixed $value, int $indent): array
    {
        $indentStr = str_repeat(' ', $indent);
        $valueExport = $this->exportValue($value);
        $exportLines = explode("\n", $valueExport);
        $lineCount = count($exportLines);

        $lines = [];
        if ($lineCount === 1) {
            $lines[] = $indentStr . "'{$key}' => {$valueExport},";
        } else {
            $lines[] = $indentStr . "'{$key}' => " . $exportLines[0];
            for ($i = 1; $i < $lineCount; $i++) {
                $lines[] = $indentStr . $exportLines[$i] . ($i === $lineCount - 1 ? ',' : '');
            }
        }

        return $lines;
    }
}
