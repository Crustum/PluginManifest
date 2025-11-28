<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Manifest;

use Cake\Utility\Inflector;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Service that performs asset installation operations
 *
 * Coordinates all install operations based on operation type:
 * - copy: Standard file copy (respects --force)
 * - copy-safe: Never overwrites existing files
 * - append: Appends to files (delegates to BootstrapAppender)
 * - append-env: Appends env vars (delegates to EnvInstaller)
 * - merge: Merges config (delegates to ConfigMerger)
 */
class Installer
{
    /**
     * Constructor
     *
     * @param \Crustum\PluginManifest\Manifest\BootstrapAppender $bootstrapAppender
     * @param \Crustum\PluginManifest\Manifest\ConfigMerger $configMerger
     * @param \Crustum\PluginManifest\Manifest\EnvInstaller $envInstaller
     * @param \Crustum\PluginManifest\Manifest\ManifestRegistry $manifest
     * @param \Crustum\PluginManifest\Manifest\DependencyInstaller|null $dependencyInstaller
     */
    public function __construct(
        protected BootstrapAppender $bootstrapAppender,
        protected ConfigMerger $configMerger,
        protected EnvInstaller $envInstaller,
        protected ManifestRegistry $manifest,
        protected ?DependencyInstaller $dependencyInstaller = null,
    ) {
    }

    /**
     * Install asset based on type
     *
     * @param array<string, mixed> $asset Asset definition
     * @param array<string, mixed> $globalOptions Global install options
     * @return \Crustum\PluginManifest\Manifest\InstallResult
     * @throws \InvalidArgumentException
     */
    public function install(array $asset, array $globalOptions = []): InstallResult
    {
        $type = $asset['type'] ?? OperationType::COPY;

        return match ($type) {
            OperationType::COPY => $this->installCopy($asset, $globalOptions),
            OperationType::COPY_SAFE => $this->installCopySafe($asset, $globalOptions),
            OperationType::APPEND => $this->installAppend($asset, $globalOptions),
            OperationType::APPEND_ENV => $this->installAppendEnv($asset, $globalOptions),
            OperationType::MERGE => $this->installMerge($asset, $globalOptions),
            OperationType::DEPENDENCIES => $this->installDependencies($asset, $globalOptions),
            default => throw new InvalidArgumentException("Unknown install type: {$type}"),
        };
    }

    /**
     * Install using copy operation
     *
     * @param array<string, mixed> $asset Asset definition
     * @param array<string, mixed> $options Install options
     * @return \Crustum\PluginManifest\Manifest\InstallResult
     */
    protected function installCopy(array $asset, array $options): InstallResult
    {
        $source = $asset['source'];
        $destination = $asset['destination'];
        $assetOptions = $asset['options'] ?? [];

        if (isset($assetOptions['rename_with_plugin']) && $assetOptions['rename_with_plugin']) {
            return $this->installMigrationsDirectory(
                $source,
                $destination,
                $assetOptions['plugin_namespace'],
                $options,
            );
        }

        if (file_exists($destination) && !($options['force'] ?? false)) {
            if ($options['existing'] ?? false) {
                return $this->installFile($source, $destination, array_merge($options, $assetOptions));
            } else {
                return new InstallResult(false, $source, $destination, 'skipped', 'File exists (use --force to overwrite)');
            }
        }

        if (is_dir($source)) {
            return $this->installDirectory($source, $destination, array_merge($options, $assetOptions));
        } else {
            return $this->installFile($source, $destination, array_merge($options, $assetOptions));
        }
    }

    /**
     * Install migrations directory with plugin namespace
     *
     * Processes all migration files in directory, adding plugin namespace
     * to prevent class conflicts while preserving original timestamps.
     *
     * @param string $sourceDir Source directory
     * @param string $destinationDir Destination directory
     * @param string $pluginName Plugin name
     * @param array<string, mixed> $options Install options
     * @return \Crustum\PluginManifest\Manifest\InstallResult
     */
    protected function installMigrationsDirectory(
        string $sourceDir,
        string $destinationDir,
        string $pluginName,
        array $options,
    ): InstallResult {
        $results = [];
        $successCount = 0;
        $skipCount = 0;

        if (!is_dir($sourceDir)) {
            return new InstallResult(false, $sourceDir, $destinationDir, 'error', 'Source directory does not exist');
        }

        $files = scandir($sourceDir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || $file === 'schema-dump-default.lock') {
                continue;
            }

            if (!preg_match('/^\d{14}_.*\.php$/', $file)) {
                continue;
            }

            $result = $this->installMigration(
                $sourceDir . DS . $file,
                $pluginName,
                $destinationDir,
            );

            $results[] = $result;

            if ($result->status === 'installed') {
                $successCount++;
            } elseif ($result->status === 'skipped') {
                $skipCount++;
            }
        }

        $message = "Installed {$successCount} migration(s), skipped {$skipCount}";

        $batchResult = new InstallResult(
            $successCount > 0,
            $sourceDir,
            $destinationDir,
            'batch-installed',
            $message,
        );
        $batchResult->setBatchResults($results);

        return $batchResult;
    }

    /**
     * Install single migration file with plugin namespace
     *
     * Adds plugin namespace prefix to filename and class name.
     * PRESERVES original timestamp to maintain inter-plugin dependency order.
     *
     * Following pattern from src/Console/Installer.php (lines 237-269)
     *
     * @param string $sourceFile Source migration file
     * @param string $pluginName Plugin name
     * @param string $destinationDir Destination directory
     * @return \Crustum\PluginManifest\Manifest\InstallResult
     */
    protected function installMigration(
        string $sourceFile,
        string $pluginName,
        string $destinationDir,
    ): InstallResult {
        $basename = basename($sourceFile);

        if (!preg_match('/^(\d{14})_(.+)\.php$/', $basename, $matches)) {
            return new InstallResult(false, $sourceFile, '', 'error', 'Invalid migration filename format');
        }

        $timestamp = $matches[1];
        $migrationName = $matches[2];

        $plugin = Inflector::camelize(str_replace('/', '_', $pluginName));
        $className = Inflector::classify($migrationName);
        $newClassName = Inflector::classify($plugin . '_' . $migrationName);

        $newBasename = $timestamp . '_' . $plugin . $className . '.php';
        $destinationFile = $destinationDir . DS . $newBasename;

        if (file_exists($destinationFile)) {
            return new InstallResult(false, $sourceFile, $destinationFile, 'skipped', 'Migration already installed');
        }

        $content = file_get_contents($sourceFile);
        if ($content === false) {
            return new InstallResult(false, $sourceFile, $destinationFile, 'error', 'Could not read source file');
        }

        $content = preg_replace(
            '/class\s+' . preg_quote($className, '/') . '\s+extends/',
            'class ' . $newClassName . ' extends',
            $content,
        );

        file_put_contents($destinationFile, $content);

        return new InstallResult(true, $sourceFile, $destinationFile, 'installed', "Installed as {$newBasename}");
    }

    /**
     * Install using copy-safe operation
     *
     * Never overwrites existing files (for configs, .env.example)
     *
     * @param array<string, mixed> $asset Asset definition
     * @param array<string, mixed> $options Install options
     * @return \Crustum\PluginManifest\Manifest\InstallResult
     */
    protected function installCopySafe(array $asset, array $options): InstallResult
    {
        $source = $asset['source'];
        $destination = $asset['destination'];

        if (file_exists($destination)) {
            return new InstallResult(false, $source, $destination, 'skipped', 'File exists (copy-safe never overwrites)');
        }

        $assetOptions = $asset['options'] ?? [];

        if (is_dir($source)) {
            return $this->installDirectory($source, $destination, $assetOptions);
        } else {
            return $this->installFile($source, $destination, $assetOptions);
        }
    }

    /**
     * Install using append operation
     *
     * Delegates to BootstrapAppender service.
     * Checks manifest to prevent duplicate appends.
     *
     * @param array<string, mixed> $asset Asset definition
     * @param array<string, mixed> $options Install options
     * @return \Crustum\PluginManifest\Manifest\InstallResult
     */
    protected function installAppend(array $asset, array $options): InstallResult
    {
        $plugin = $asset['plugin'] ?? 'Unknown';
        $tag = $asset['tag'] ?? 'bootstrap';

        if ($this->manifest->isOperationCompleted($plugin, 'append', $tag, $asset)) {
            if (!($options['force'] ?? false)) {
                return new InstallResult(
                    false,
                    $asset['content'],
                    $asset['destination'],
                    'skipped',
                    'Bootstrap append already completed (use --force to re-append)',
                );
            }
        }

        $result = $this->bootstrapAppender->append(
            $asset['destination'],
            $asset['content'],
            $asset['marker'] ?? null,
            $options['dry_run'] ?? false,
        );

        if ($result->success && !($options['dry_run'] ?? false)) {
            $this->manifest->recordInstalled($plugin, 'append', $tag, [
                'destination' => $asset['destination'],
                'marker' => $asset['marker'] ?? null,
                'completed' => true,
            ]);
        }

        return $result;
    }

    /**
     * Install using append-env operation
     *
     * Delegates to EnvInstaller service.
     * Can be re-run as it checks each var individually.
     *
     * @param array<string, mixed> $asset Asset definition
     * @param array<string, mixed> $options Install options
     * @return \Crustum\PluginManifest\Manifest\InstallResult
     */
    protected function installAppendEnv(array $asset, array $options): InstallResult
    {
        $plugin = $asset['plugin'] ?? 'Unknown';
        $tag = $asset['tag'] ?? 'envs';

        $result = $this->envInstaller->appendVars(
            $asset['destination'],
            $asset['env_vars'],
            $asset['comment'] ?? null,
            $options['dry_run'] ?? false,
        );

        if ($result->success && !($options['dry_run'] ?? false)) {
            $this->manifest->recordInstalled($plugin, OperationType::APPEND_ENV, $tag, [
                'destination' => $asset['destination'],
                'env_vars' => array_keys($asset['env_vars']),
                'added_count' => count($asset['env_vars']),
            ]);
        }

        return $result;
    }

    /**
     * Install using merge operation
     *
     * Delegates to ConfigMerger service.
     * Checks manifest to prevent duplicate merges.
     *
     * @param array<string, mixed> $asset Asset definition
     * @param array<string, mixed> $options Install options
     * @return \Crustum\PluginManifest\Manifest\InstallResult
     */
    protected function installMerge(array $asset, array $options): InstallResult
    {
        $plugin = $asset['plugin'] ?? 'Unknown';
        $tag = $asset['tag'] ?? 'config';

        if ($this->manifest->isOperationCompleted($plugin, 'merge', $tag, $asset)) {
            if (!($options['force'] ?? false)) {
                return new InstallResult(
                    false,
                    $asset['key'],
                    $asset['destination'],
                    'skipped',
                    'Config merge already completed (key exists)',
                );
            }
        }

        $result = $this->configMerger->merge(
            $asset['destination'],
            $asset['key'],
            $asset['value'],
            $options['dry_run'] ?? false,
        );

        if ($result->success && !($options['dry_run'] ?? false)) {
            $this->manifest->recordInstalled($plugin, 'merge', $tag, [
                'destination' => $asset['destination'],
                'key' => $asset['key'],
                'completed' => true,
            ]);
        }

        return $result;
    }

    /**
     * Install dependencies
     *
     * Delegates to DependencyInstaller service if available.
     * Requires ConsoleIo for user interaction.
     *
     * @param array<string, mixed> $asset Asset definition
     * @param array<string, mixed> $options Install options
     * @return \Crustum\PluginManifest\Manifest\InstallResult
     */
    protected function installDependencies(array $asset, array $options): InstallResult
    {
        if ($this->dependencyInstaller === null) {
            return new InstallResult(
                false,
                'dependencies',
                'dependencies',
                'error',
                'DependencyInstaller not available (requires ConsoleIo)',
            );
        }

        if (!isset($options['console_io'])) {
            return new InstallResult(
                false,
                'dependencies',
                'dependencies',
                'error',
                'Dependencies require interactive console (ConsoleIo not provided)',
            );
        }

        return $this->dependencyInstaller->installDependencies(
            $asset,
            $options,
            $options['console_io'],
        );
    }

    /**
     * Install single file
     *
     * @param string $from Source file
     * @param string $to Destination file
     * @param array<string, mixed> $options Install options
     * @return \Crustum\PluginManifest\Manifest\InstallResult
     */
    protected function installFile(string $from, string $to, array $options): InstallResult
    {
        $this->createParentDirectory(dirname($to));

        if (!($options['dry_run'] ?? false)) {
            copy($from, $to);
        }

        return new InstallResult(true, $from, $to, $options['dry_run'] ?? false ? 'would-install' : 'installed');
    }

    /**
     * Install directory recursively
     *
     * @param string $from Source directory
     * @param string $to Destination directory
     * @param array<string, mixed> $options Install options
     * @return \Crustum\PluginManifest\Manifest\InstallResult
     */
    protected function installDirectory(string $from, string $to, array $options): InstallResult
    {
        if (!is_dir($from)) {
            return new InstallResult(false, $from, $to, 'error', 'Source directory does not exist');
        }

        $this->createParentDirectory($to);
        $this->copyDirectoryRecursive($from, $to);

        return new InstallResult(true, $from, $to, 'installed');
    }

    /**
     * Copy directory recursively
     *
     * @param string $source Source directory
     * @param string $destination Destination directory
     * @return void
     */
    protected function copyDirectoryRecursive(string $source, string $destination): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $targetPath = $destination . DS . $iterator->getSubPathName();

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
            } else {
                $targetDir = dirname($targetPath);
                if (!is_dir($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }
                copy($item->getPathname(), $targetPath);
            }
        }
    }

    /**
     * Create parent directory if it doesn't exist
     *
     * @param string $directory Directory path
     * @return void
     */
    protected function createParentDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }
}
