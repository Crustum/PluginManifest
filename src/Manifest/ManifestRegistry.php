<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Manifest;

/**
 * Registry tracking installed plugin assets
 *
 * This registry tracks what assets have been installed with different rules
 * per operation type:
 * - Migrations: Can be re-installed (new migrations may appear)
 * - Env vars: Can be re-installed (checks each var individually)
 * - Bootstrap appends: Once only (marked as completed)
 * - Config merges: Once only (key exists check)
 */
class ManifestRegistry
{
    /**
     * Path to manifest registry file
     */
    protected const MANIFEST_FILE = CONFIG . 'manifest_registry.php';

    /**
     * Record installed asset
     *
     * @param string $plugin Plugin name
     * @param string $operationType Operation type (copy, append, merge, append-env)
     * @param string $tag Asset tag
     * @param array<string, mixed> $assetData Asset metadata
     * @return void
     */
    public function recordInstalled(
        string $plugin,
        string $operationType,
        string $tag,
        array $assetData,
    ): void {
        $manifest = $this->load();

        if (!isset($manifest[$plugin])) {
            $manifest[$plugin] = [];
        }

        if (!isset($manifest[$plugin][$operationType])) {
            $manifest[$plugin][$operationType] = [];
        }

        if (!isset($manifest[$plugin][$operationType][$tag])) {
            $manifest[$plugin][$operationType][$tag] = [];
        }

        $assetData = $this->normalizePathsForStorage($assetData);

        $manifest[$plugin][$operationType][$tag][] = array_merge($assetData, [
            'installed_at' => date('Y-m-d H:i:s'),
        ]);

        $this->save($manifest);
    }

    /**
     * Check if operation has been completed
     *
     * Different logic for different operation types:
     * - append/merge: Check if completed (once only)
     * - append-env: Always false (can re-run for new vars)
     * - copy: Check if file exists
     *
     * @param string $plugin Plugin name
     * @param string $operationType Operation type
     * @param string $tag Asset tag
     * @param array<string, mixed> $asset Asset definition
     * @return bool
     */
    public function isOperationCompleted(string $plugin, string $operationType, string $tag, array $asset): bool
    {
        $manifest = $this->load();

        if (!isset($manifest[$plugin][$operationType][$tag])) {
            return false;
        }

        return match ($operationType) {
            OperationType::APPEND => $this->isAppendCompleted($manifest[$plugin][$operationType][$tag], $asset),
            OperationType::APPEND_ENV => false,
            OperationType::MERGE => $this->isMergeCompleted($manifest[$plugin][$operationType][$tag], $asset),
            OperationType::COPY, OperationType::COPY_SAFE => $this->isCopyCompleted($manifest[$plugin][$operationType][$tag], $asset),
            default => false,
        };
    }

    /**
     * Check if append operation is completed
     *
     * @param array<int, array<string, mixed>> $records Manifest records
     * @param array<string, mixed> $asset Asset definition
     * @return bool
     */
    protected function isAppendCompleted(array $records, array $asset): bool
    {
        foreach ($records as $record) {
            if (
                $record['destination'] === $asset['destination'] &&
                $record['marker'] === ($asset['marker'] ?? null)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if merge operation is completed
     *
     * @param array<int, array<string, mixed>> $records Manifest records
     * @param array<string, mixed> $asset Asset definition
     * @return bool
     */
    protected function isMergeCompleted(array $records, array $asset): bool
    {
        foreach ($records as $record) {
            if (
                $record['destination'] === $asset['destination'] &&
                $record['key'] === $asset['key']
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if copy operation is completed
     *
     * @param array<int, array<string, mixed>> $records Manifest records
     * @param array<string, mixed> $asset Asset definition
     * @return bool
     */
    protected function isCopyCompleted(array $records, array $asset): bool
    {
        $source = $asset['source'] ?? '';
        $destination = $asset['destination'];

        if (!$this->isAbsolutePath($destination)) {
            $destination = $this->toAbsolutePath($destination);
        }

        if (!$this->isAbsolutePath($source)) {
            $source = $this->toAbsolutePath($source);
        }

        if (is_dir($source)) {
            return $this->isDirectoryCopyCompleted($source, $records);
        }

        return file_exists($destination);
    }

    /**
     * Check if directory copy is completed by comparing source files with records
     *
     * @param string $sourceDir Source directory
     * @param array<int, array<string, mixed>> $records Registry records
     * @return bool
     */
    protected function isDirectoryCopyCompleted(string $sourceDir, array $records): bool
    {
        if (!is_dir($sourceDir)) {
            return false;
        }

        $files = scandir($sourceDir);
        $migrationFiles = [];

        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || $file === 'schema-dump-default.lock') {
                continue;
            }

            if (preg_match('/^\d{14}_.*\.php$/', $file)) {
                $migrationFiles[] = $file;
            }
        }

        if (empty($migrationFiles)) {
            return true;
        }

        foreach ($migrationFiles as $file) {
            $sourceFile = $sourceDir . DS . $file;
            $sourceFile = $this->toRelativePath($sourceFile);

            $found = false;
            foreach ($records as $record) {
                if (isset($record['source']) && $record['source'] === $sourceFile) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if operation type can be re-installed
     *
     * @param string $operationType Operation type
     * @return bool
     */
    public function canReinstall(string $operationType): bool
    {
        return match ($operationType) {
            OperationType::COPY => true,
            OperationType::APPEND_ENV => true,
            OperationType::APPEND => false,
            OperationType::MERGE => false,
            OperationType::COPY_SAFE => false,
            default => false,
        };
    }

    /**
     * Get installed assets
     *
     * @param string|null $plugin Plugin name filter
     * @param string|null $operationType Operation type filter
     * @param string|null $tag Tag filter
     * @return array<string, mixed>
     */
    public function getInstalled(?string $plugin = null, ?string $operationType = null, ?string $tag = null): array
    {
        $manifest = $this->load();

        if ($plugin && isset($manifest[$plugin])) {
            $data = $manifest[$plugin];

            if ($operationType && isset($data[$operationType])) {
                $data = $data[$operationType];

                if ($tag && isset($data[$tag])) {
                    return $data[$tag];
                }

                return $data;
            }

            return $data;
        }

        return $manifest;
    }

    /**
     * Load manifest from file
     *
     * @return array<string, mixed>
     */
    protected function load(): array
    {
        if (!file_exists(static::MANIFEST_FILE)) {
            return [];
        }

        return require static::MANIFEST_FILE;
    }

    /**
     * Save manifest to file
     *
     * @param array<string, mixed> $manifest Manifest data
     * @return void
     */
    protected function save(array $manifest): void
    {
        $directory = dirname(static::MANIFEST_FILE);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $exported = $this->exportArray($manifest, 0);
        $content = "<?php\n\nreturn " . $exported . ";\n";
        file_put_contents(static::MANIFEST_FILE, $content);
    }

    /**
     * Export array to PHP code with short syntax
     *
     * @param array<mixed> $array Array to export
     * @param int $indent Indentation level
     * @return string Exported PHP code
     */
    protected function exportArray(array $array, int $indent): string
    {
        if (empty($array)) {
            return '[]';
        }

        $keys = array_keys($array);
        $isSequential = $keys === array_keys($keys);
        $indentStr = str_repeat('    ', $indent);
        $nextIndentStr = str_repeat('    ', $indent + 1);

        $lines = [];
        $lines[] = '[';

        foreach ($array as $key => $value) {
            if ($isSequential) {
                $lines[] = $nextIndentStr . $this->exportValue($value, $indent + 1) . ',';
            } else {
                $exportedKey = is_int($key) ? (string)$key : "'{$key}'";
                $lines[] = $nextIndentStr . $exportedKey . ' => ' . $this->exportValue($value, $indent + 1) . ',';
            }
        }

        $lines[] = $indentStr . ']';

        return implode("\n", $lines);
    }

    /**
     * Export value to string
     *
     * @param mixed $value Value to export
     * @param int $indent Indent level
     * @return string
     */
    protected function exportValue(mixed $value, int $indent): string
    {
        if (is_array($value)) {
            return $this->exportArray($value, $indent);
        }

        if (is_string($value)) {
            return "'" . addslashes($value) . "'";
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_null($value)) {
            return 'null';
        }

        return (string)$value;
    }

    /**
     * Normalize paths for storage (relative to ROOT with forward slashes)
     *
     * @param array<string, mixed> $assetData Asset data
     * @return array<string, mixed> Normalized asset data
     */
    protected function normalizePathsForStorage(array $assetData): array
    {
        $pathKeys = ['source', 'destination'];

        foreach ($pathKeys as $key) {
            if (isset($assetData[$key])) {
                $assetData[$key] = $this->toRelativePath($assetData[$key]);
            }
        }

        return $assetData;
    }

    /**
     * Convert absolute path to relative path from ROOT with forward slashes
     *
     * @param string $path Absolute path
     * @return string Relative path with forward slashes
     */
    protected function toRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $root = str_replace('\\', '/', ROOT);

        if (strpos($path, $root) === 0) {
            $path = substr($path, strlen($root));
            $path = ltrim($path, '/');
        }

        return $path;
    }

    /**
     * Convert relative path to absolute path
     *
     * @param string $path Relative path
     * @return string Absolute path with DS
     */
    protected function toAbsolutePath(string $path): string
    {
        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return ROOT . DS . str_replace('/', DS, $path);
    }

    /**
     * Check if path is absolute
     *
     * @param string $path Path to check
     * @return bool
     */
    protected function isAbsolutePath(string $path): bool
    {
        if (strlen($path) === 0) {
            return false;
        }

        if ($path[0] === '/' || $path[0] === DS) {
            return true;
        }

        if (strlen($path) > 1 && $path[1] === ':') {
            return true;
        }

        return false;
    }

    /**
     * Record dependency relationship
     *
     * @param string $parentPlugin Parent plugin that has dependencies
     * @param string $dependencyPlugin Dependency plugin that was installed
     * @param array<string, mixed> $dependencyConfig Dependency configuration
     * @return void
     */
    public function recordDependency(
        string $parentPlugin,
        string $dependencyPlugin,
        array $dependencyConfig,
    ): void {
        $manifest = $this->load();

        if (!isset($manifest['_dependencies'])) {
            $manifest['_dependencies'] = [];
        }

        if (!isset($manifest['_dependencies'][$parentPlugin])) {
            $manifest['_dependencies'][$parentPlugin] = [];
        }

        $manifest['_dependencies'][$parentPlugin][$dependencyPlugin] = array_merge($dependencyConfig, [
            'installed_at' => date('Y-m-d H:i:s'),
        ]);

        $this->save($manifest);
    }

    /**
     * Get dependencies for a plugin
     *
     * @param string $plugin Plugin name
     * @return array<string, array<string, mixed>> Dependencies with their config
     */
    public function getDependencies(string $plugin): array
    {
        $manifest = $this->load();

        return $manifest['_dependencies'][$plugin] ?? [];
    }

    /**
     * Get all plugins that depend on a given plugin
     *
     * @param string $plugin Plugin name
     * @return array<string> List of plugins that depend on this plugin
     */
    public function getDependents(string $plugin): array
    {
        $manifest = $this->load();
        $dependents = [];

        if (!isset($manifest['_dependencies'])) {
            return [];
        }

        foreach ($manifest['_dependencies'] as $parentPlugin => $dependencies) {
            if (isset($dependencies[$plugin])) {
                $dependents[] = $parentPlugin;
            }
        }

        return $dependents;
    }

    /**
     * Check if a plugin has recorded dependencies
     *
     * @param string $plugin Plugin name
     * @return bool
     */
    public function hasDependencies(string $plugin): bool
    {
        $manifest = $this->load();

        return isset($manifest['_dependencies'][$plugin]) &&
               !empty($manifest['_dependencies'][$plugin]);
    }

    /**
     * Get plugin installation status
     *
     * @param string $plugin Plugin name
     * @return array<string, mixed> Status information
     */
    public function getPluginStatus(string $plugin): array
    {
        $manifest = $this->load();

        if (!isset($manifest[$plugin])) {
            return [
                'installed' => false,
                'operations' => [],
                'dependencies' => [],
            ];
        }

        return [
            'installed' => true,
            'operations' => $manifest[$plugin],
            'dependencies' => $this->getDependencies($plugin),
            'depended_by' => $this->getDependents($plugin),
        ];
    }

    /**
     * Get all plugin statuses from registry
     *
     * @return array<string, array<string, mixed>> All plugin statuses
     */
    public function getAllPluginStatuses(): array
    {
        $manifest = $this->load();
        $statuses = [];

        foreach ($manifest as $pluginName => $operations) {
            if ($pluginName === '_dependencies') {
                continue;
            }

            $statuses[$pluginName] = [
                'installed' => true,
                'operations' => $operations,
                'dependencies' => $this->getDependencies($pluginName),
                'depended_by' => $this->getDependents($pluginName),
            ];
        }

        return $statuses;
    }
}
