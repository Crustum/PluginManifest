<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Manifest;

use Cake\Console\ConsoleIo;
use Cake\Core\Plugin;
use Exception;
use InvalidArgumentException;

/**
 * Service for installing plugin dependencies
 *
 * Handles the installation of plugin dependencies including user prompts,
 * dependency resolution, and coordinated installation of multiple plugins.
 */
class DependencyInstaller
{
    /**
     * Constructor
     *
     * @param \Crustum\PluginManifest\Manifest\DependencyResolver $resolver
     * @param \Crustum\PluginManifest\Manifest\Installer $installer
     * @param \Crustum\PluginManifest\Manifest\ManifestRegistry $registry
     */
    public function __construct(
        protected DependencyResolver $resolver,
        protected Installer $installer,
        protected ManifestRegistry $registry,
    ) {
    }

    /**
     * Install dependencies for a plugin
     *
     * @param array<string, mixed> $dependencyAsset Dependency asset definition
     * @param array<string, mixed> $options Installation options
     * @param \Cake\Console\ConsoleIo $io Console IO for user interaction
     * @return \Crustum\PluginManifest\Manifest\InstallResult
     */
    public function installDependencies(
        array $dependencyAsset,
        array $options,
        ConsoleIo $io,
    ): InstallResult {
        $dependencies = $dependencyAsset['dependencies'] ?? [];
        $parentPlugin = $dependencyAsset['plugin'] ?? 'Unknown';

        if (empty($dependencies)) {
            return new InstallResult(
                true,
                'dependencies',
                'dependencies',
                'skipped',
                'No dependencies defined',
            );
        }

        $io->out('');
        $io->out("<info>Processing dependencies for {$parentPlugin}...</info>");

        $availablePlugins = $this->discoverAvailablePlugins();
        $dependencyInfo = $this->resolver->getDependencyInfo($dependencies);

        $directDeps = $this->promptForDependencies($dependencyInfo, $availablePlugins, $options, $io);

        if (empty($directDeps)) {
            return new InstallResult(
                true,
                'dependencies',
                'dependencies',
                'skipped',
                'No dependencies selected for installation',
            );
        }

        $filterOptions = [
            'install_optional' => false,
            'force_all' => $options['all_deps'] ?? false,
        ];

        try {
            $completeDependencyTree = $this->resolver->buildDependencyTree(
                $availablePlugins,
                array_keys($directDeps),
                $filterOptions,
            );
        } catch (InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return new InstallResult(
                false,
                'dependencies',
                'dependencies',
                'error',
                'Circular dependency detected',
            );
        }

        if (empty($completeDependencyTree)) {
            $io->out('<info>No transitive dependencies found.</info>');

            return $this->installSelectedDependencies($directDeps, $dependencies, $options, $io, $parentPlugin);
        }

        $io->out('');
        $io->out('<info>Discovered complete dependency tree (deepest first):</info>');
        foreach (array_keys($completeDependencyTree) as $index => $depName) {
            $io->out('  ' . ($index + 1) . ". {$depName}");
        }

        return $this->installSelectedDependencies($completeDependencyTree, $completeDependencyTree, $options, $io, $parentPlugin);
    }

    /**
     * Discover available plugins that implement ManifestInterface
     *
     * @return array<string, array<string, mixed>> Available plugins with their manifest data
     */
    protected function discoverAvailablePlugins(): array
    {
        $plugins = [];

        foreach (Plugin::loaded() as $pluginName) {
            $plugin = Plugin::getCollection()->get($pluginName);
            $pluginClass = get_class($plugin);

            if (is_subclass_of($pluginClass, ManifestInterface::class)) {
                try {
                    $assets = $pluginClass::manifest();
                    if (!empty($assets)) {
                        $plugins[$pluginName] = [
                            'class' => $pluginClass,
                            'assets' => $this->organizeAssetsByTag($assets, $pluginName),
                        ];
                    }
                } catch (Exception) {
                    // Skip plugins with manifest errors
                }
            }
        }

        return $plugins;
    }

    /**
     * Organize assets by tag for easier filtering
     *
     * @param array<int, array<string, mixed>> $assets Asset definitions
     * @param string $pluginName Plugin name
     * @return array<string, array<int, array<string, mixed>>> Assets organized by tag
     */
    protected function organizeAssetsByTag(array $assets, string $pluginName): array
    {
        $organized = [];

        foreach ($assets as $asset) {
            $tag = $asset['tag'] ?? 'default';
            if (!isset($organized[$tag])) {
                $organized[$tag] = [];
            }

            $asset['plugin'] = $pluginName;
            $organized[$tag][] = $asset;
        }

        return $organized;
    }

    /**
     * Prompt user for dependency installation choices
     *
     * @param array<string, array<string, mixed>> $dependencyInfo Dependency information
     * @param array<string, array<string, mixed>> $availablePlugins Available plugins
     * @param array<string, mixed> $options Installation options
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @return array<string, array<string, mixed>> Dependencies to install
     */
    protected function promptForDependencies(
        array $dependencyInfo,
        array $availablePlugins,
        array $options,
        ConsoleIo $io,
    ): array {
        $toInstall = [];
        $withDependencies = $options['with_dependencies'] ?? false;
        $allDeps = $options['all_deps'] ?? false;
        $dryRun = $options['dry_run'] ?? false;

        if (!$withDependencies) {
            return [];
        }

        $io->out('');
        $io->out('<info>Dependencies found:</info>');

        foreach ($dependencyInfo as $pluginName => $info) {
            $available = isset($availablePlugins[$pluginName]);
            $required = $info['required'];
            $conditionMet = $info['condition_met'];

            $statusIcon = $required ? '✓' : '?';
            $typeLabel = $required ? 'required' : 'optional';
            $availableLabel = $available ? '' : ' <error>(not available)</error>';
            $conditionLabel = $conditionMet ? '' : ' <warning>(condition not met)</warning>';

            $io->out("  {$statusIcon} <warning>{$pluginName}</warning> ({$typeLabel}){$availableLabel}{$conditionLabel} - {$info['reason']}");

            if (!$available) {
                $io->out("    <error>Plugin {$pluginName} is not loaded or does not implement ManifestInterface</error>");
                continue;
            }

            if (!$conditionMet && !$required) {
                continue;
            }

            if ($required || $allDeps) {
                $toInstall[$pluginName] = $info;
            } elseif (!$dryRun) {
                $install = $io->askChoice(
                    $info['prompt'],
                    ['y', 'n'],
                    'n',
                );

                if ($install === 'y') {
                    $toInstall[$pluginName] = $info;
                }
            }
        }

        return $toInstall;
    }

    /**
     * Install selected dependencies in correct order
     *
     * @param array<string, array<string, mixed>> $toInstall Dependencies to install
     * @param array<string, array<string, mixed>> $allDependencies All dependency configurations
     * @param array<string, mixed> $options Installation options
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @param string $parentPlugin Parent plugin name for tracking
     * @return \Crustum\PluginManifest\Manifest\InstallResult
     */
    protected function installSelectedDependencies(
        array $toInstall,
        array $allDependencies,
        array $options,
        ConsoleIo $io,
        string $parentPlugin = '',
    ): InstallResult {
        if (empty($toInstall)) {
            return new InstallResult(
                true,
                'dependencies',
                'dependencies',
                'skipped',
                'No dependencies to install',
            );
        }

        $io->out('');
        $io->out('<info>Dependency installation order:</info>');

        $installOrder = [];
        foreach (array_keys($toInstall) as $pluginName) {
            $installOrder[] = $pluginName;
            $tags = $allDependencies[$pluginName]['tags'] ?? ['all'];
            $tagList = is_array($tags) ? implode(', ', $tags) : 'all';
            $io->out("  {$pluginName} ({$tagList})");
        }

        if (!($options['dry_run'] ?? false)) {
            $proceed = $io->askChoice(
                'Proceed with dependency installation?',
                ['y', 'n'],
                'y',
            );

            if ($proceed !== 'y') {
                return new InstallResult(
                    false,
                    'dependencies',
                    'dependencies',
                    'cancelled',
                    'Installation cancelled by user',
                );
            }
        }

        $results = [];
        $successCount = 0;
        $errorCount = 0;
        $skippedCount = 0;
        $installed = [];

        foreach ($installOrder as $pluginName) {
            if (isset($installed[$pluginName])) {
                $skippedCount++;
                $io->out("<info>  [SKIP] {$pluginName} (already installed in this session)</info>");
                continue;
            }

            $io->out('');
            $io->out("<comment>Installing {$pluginName} dependencies...</comment>");

            $result = $this->installSinglePluginDependency(
                $pluginName,
                $allDependencies[$pluginName],
                $options,
                $io,
            );

            $results[] = $result;

            if ($result->success) {
                $successCount++;
                $installed[$pluginName] = true;

                if ($parentPlugin !== '' && !($options['dry_run'] ?? false)) {
                    $this->registry->recordDependency(
                        $parentPlugin,
                        $pluginName,
                        $allDependencies[$pluginName],
                    );
                }
            } else {
                $errorCount++;
                if ($allDependencies[$pluginName]['required'] ?? false) {
                    $io->error("Required dependency {$pluginName} failed to install. Stopping.");
                    break;
                }
            }
        }

        $message = "Dependencies: {$successCount} installed";
        if ($skippedCount > 0) {
            $message .= ", {$skippedCount} skipped (duplicates)";
        }
        if ($errorCount > 0) {
            $message .= ", {$errorCount} failed";
        }

        $batchResult = new InstallResult(
            $errorCount === 0,
            'dependencies',
            'dependencies',
            $errorCount === 0 ? 'installed' : 'partial',
            $message,
        );
        $batchResult->setBatchResults($results);

        return $batchResult;
    }

    /**
     * Install dependencies for a single plugin
     *
     * @param string $pluginName Plugin name
     * @param array<string, mixed> $dependencyConfig Dependency configuration
     * @param array<string, mixed> $options Installation options
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @return \Crustum\PluginManifest\Manifest\InstallResult
     */
    protected function installSinglePluginDependency(
        string $pluginName,
        array $dependencyConfig,
        array $options,
        ConsoleIo $io,
    ): InstallResult {
        try {
            $plugin = Plugin::getCollection()->get($pluginName);
            $pluginClass = get_class($plugin);

            if (!is_subclass_of($pluginClass, ManifestInterface::class)) {
                return new InstallResult(
                    false,
                    $pluginName,
                    $pluginName,
                    'error',
                    "{$pluginName} does not implement ManifestInterface",
                );
            }

            $assets = $pluginClass::manifest();
            $organizedAssets = $this->organizeAssetsByTag($assets, $pluginName);

            $tagsToInstall = $dependencyConfig['tags'] ?? null;
            if ($tagsToInstall === null) {
                $assetsToInstall = $assets;
            } else {
                $assetsToInstall = [];
                foreach ((array)$tagsToInstall as $tag) {
                    if (isset($organizedAssets[$tag])) {
                        $assetsToInstall = array_merge($assetsToInstall, $organizedAssets[$tag]);
                    }
                }
            }

            $successCount = 0;
            $errorCount = 0;

            foreach ($assetsToInstall as $asset) {
                if (($asset['type'] ?? '') === OperationType::DEPENDENCIES) {
                    continue;
                }

                $result = $this->installer->install($asset, $options);

                if ($result->success) {
                    $successCount++;
                } else {
                    $errorCount++;
                }

                $this->displayResult($io, $result);
            }

            $message = "{$pluginName}: {$successCount} installed, {$errorCount} failed";

            return new InstallResult(
                $errorCount === 0,
                $pluginName,
                $pluginName,
                $errorCount === 0 ? 'installed' : 'partial',
                $message,
            );
        } catch (Exception $e) {
            return new InstallResult(
                false,
                $pluginName,
                $pluginName,
                'error',
                "Failed to install {$pluginName}: " . $e->getMessage(),
            );
        }
    }

    /**
     * Display installation result
     *
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @param \Crustum\PluginManifest\Manifest\InstallResult $result Install result
     * @return void
     */
    protected function displayResult(ConsoleIo $io, InstallResult $result): void
    {
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
        $source = $this->truncatePath($result->source, 40);
        $destination = $this->truncatePath($result->destination, 40);

        $message = match ($result->status) {
            'installed' => "    <{$color}>[✓]</{$color}> {$source} → {$destination}",
            'batch-installed' => "    <{$color}>[✓]</{$color}> {$result->message}",
            'appended' => "    <{$color}>[✓]</{$color}> Appended to {$destination}",
            'merged' => "    <{$color}>[✓]</{$color}> Merged '{$source}' to {$destination}",
            'skipped' => "    <{$color}>[SKIP]</{$color}> {$destination}" .
                         ($result->message ? " ({$result->message})" : ''),
            'error' => "    <{$color}>[✗]</{$color}> {$source}: {$result->message}",
            default => "    [{$result->status}] {$source}",
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
    protected function truncatePath(string $path, int $maxLength): string
    {
        if (strlen($path) <= $maxLength) {
            return $path;
        }

        return '...' . substr($path, -$maxLength + 3);
    }
}
