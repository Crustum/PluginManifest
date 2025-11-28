<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Plugin;
use Crustum\PluginManifest\Manifest\BootstrapAppender;
use Crustum\PluginManifest\Manifest\ConfigMerger;
use Crustum\PluginManifest\Manifest\DependencyInstaller;
use Crustum\PluginManifest\Manifest\DependencyResolver;
use Crustum\PluginManifest\Manifest\EnvInstaller;
use Crustum\PluginManifest\Manifest\Installer;
use Crustum\PluginManifest\Manifest\InstallResult;
use Crustum\PluginManifest\Manifest\ManifestInterface;
use Crustum\PluginManifest\Manifest\ManifestRegistry;
use Exception;

/**
 * ManifestInstall command
 *
 * Allows plugins implementing ManifestInterface to install their assets
 * to the application (config files, migrations, templates, etc.)
 */
class ManifestInstallCommand extends Command
{
    /**
     * Hook method for defining this command's option parser
     *
     * @param \Cake\Console\ConsoleOptionParser $parser The parser to be defined
     * @return \Cake\Console\ConsoleOptionParser The built parser
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Install plugin assets to the application')
            ->addOption('plugin', [
                'short' => 'p',
                'help' => 'The plugin to install assets from',
            ])
            ->addOption('tag', [
                'short' => 't',
                'help' => 'The tag to install (config, migrations, webroot, etc.)',
            ])
            ->addOption('force', [
                'short' => 'f',
                'help' => 'Overwrite existing files',
                'boolean' => true,
            ])
            ->addOption('existing', [
                'short' => 'e',
                'help' => 'Only update files that were previously installed',
                'boolean' => true,
            ])
            ->addOption('all', [
                'short' => 'a',
                'help' => 'Install all assets from all plugins',
                'boolean' => true,
            ])
            ->addOption('dry_run', [
                'short' => 'd',
                'help' => 'Preview what would be installed without making changes',
                'boolean' => true,
            ])
            ->addOption('with_dependencies', [
                'help' => 'Install plugin dependencies (prompts for optional ones)',
                'boolean' => true,
            ])
            ->addOption('all_deps', [
                'help' => 'Install all dependencies without prompting',
                'boolean' => true,
            ])
            ->addOption('no_dependencies', [
                'help' => 'Skip dependency installation',
                'boolean' => true,
            ])
            ->addOption('update_dependencies', [
                'help' => 'Update existing dependencies (re-install with --existing flag)',
                'boolean' => true,
            ]);
    }

    /**
     * Implement this method with your command's logic
     *
     * @param \Cake\Console\Arguments $args The command arguments
     * @param \Cake\Console\ConsoleIo $io The console io
     * @return int|null The exit code or null for success
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $manifest = new ManifestRegistry();
        $bootstrapAppender = new BootstrapAppender();
        $configMerger = new ConfigMerger();
        $envInstaller = new EnvInstaller();

        $installer = new Installer($bootstrapAppender, $configMerger, $envInstaller, $manifest);
        $dependencyResolver = new DependencyResolver();
        $dependencyInstaller = new DependencyInstaller($dependencyResolver, $installer, $manifest);
        $installer = new Installer($bootstrapAppender, $configMerger, $envInstaller, $manifest, $dependencyInstaller);

        $pluginFilter = $args->getOption('plugin');
        $tagFilter = $args->getOption('tag');
        $all = $args->getOption('all');
        $force = $args->getOption('force');
        $existing = $args->getOption('existing');
        $dryRun = $args->getOption('dry_run');
        $withDependencies = $args->getOption('with_dependencies');
        $allDeps = $args->getOption('all_deps');
        $noDependencies = $args->getOption('no_dependencies');
        $updateDependencies = $args->getOption('update_dependencies');

        if ($dryRun) {
            $io->info('<info>Dry run mode: No changes will be made</info>');
            $io->out('');
        }

        $plugins = $this->discoverPublishablePlugins($io);

        if (empty($plugins)) {
            $io->warning('No plugins with publishable assets found.');

            return static::CODE_SUCCESS;
        }

        if (!$pluginFilter && !$all) {
            [$pluginFilter, $tagFilter] = $this->promptForSelection($io, $plugins);

            if (!$pluginFilter) {
                $io->info('Installation cancelled.');

                return static::CODE_SUCCESS;
            }
        }

        $options = [
            'force' => $force,
            'existing' => $existing || $updateDependencies,
            'dry_run' => $dryRun,
            'with_dependencies' => $withDependencies || $updateDependencies,
            'all_deps' => $allDeps,
            'no_dependencies' => $noDependencies,
            'update_dependencies' => $updateDependencies,
            'console_io' => $io,
        ];

        if ($all) {
            return $this->installAllPlugins($io, $plugins, $options, $installer, $manifest);
        }

        if ($pluginFilter && !isset($plugins[$pluginFilter])) {
            $io->error("Plugin '{$pluginFilter}' not found or does not implement ManifestInterface.");

            return static::CODE_ERROR;
        }

        $pluginName = is_string($pluginFilter) ? $pluginFilter : '';
        $tag = is_string($tagFilter) ? $tagFilter : null;

        return $this->installPlugin($io, $pluginName, $plugins[$pluginName], $tag, $options, $installer, $manifest);
    }

    /**
     * Discover plugins that implement ManifestInterface
     *
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @return array<string, array<string, mixed>> Plugin data keyed by plugin name
     */
    protected function discoverPublishablePlugins(ConsoleIo $io): array
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
                } catch (Exception $e) {
                    $io->warning("Failed to get assets from {$pluginName}: " . $e->getMessage());
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
     * Prompt user to select what to install
     *
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @param array<string, array<string, mixed>> $plugins Available plugins
     * @return array{string|null, int|string|null} [plugin name, tag name]
     */
    protected function promptForSelection(ConsoleIo $io, array $plugins): array
    {
        $io->out('<info>Available plugins with publishable assets:</info>');
        $io->out('');

        $choices = [];
        $choiceMap = [];
        $index = 0;

        foreach ($plugins as $pluginName => $pluginData) {
            $tags = array_keys($pluginData['assets']);
            $io->out("  <warning>{$pluginName}</warning>: " . implode(', ', $tags));

            $choices[] = "All from {$pluginName}";
            $choiceMap[$index] = [$pluginName, null];
            $index++;

            foreach ($tags as $tag) {
                $assetCount = count($pluginData['assets'][$tag]);
                $choices[] = "  {$pluginName} > {$tag} ({$assetCount} asset(s))";
                $choiceMap[$index] = [$pluginName, $tag];
                $index++;
            }
        }

        $io->out('');
        $selection = $io->askChoice(
            'What would you like to install?',
            array_merge(['Cancel'], $choices),
            '0',
        );

        if ($selection === 'Cancel') {
            return [null, null];
        }

        $selectedIndex = array_search($selection, $choices, true);

        if ($selectedIndex === false) {
            return [null, null];
        }

        return $choiceMap[$selectedIndex] ?? [null, null];
    }

    /**
     * Install assets from all plugins
     *
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @param array<string, array<string, mixed>> $plugins Plugin data
     * @param array<string, mixed> $options Install options
     * @param \Crustum\PluginManifest\Manifest\Installer $installer Installer instance
     * @return int Exit code
     */
    protected function installAllPlugins(ConsoleIo $io, array $plugins, array $options, Installer $installer, ManifestRegistry $registry): int
    {
        $totalSuccess = 0;
        $totalErrors = 0;

        foreach ($plugins as $pluginName => $pluginData) {
            $io->out('');
            $io->out("<info>Installing assets from {$pluginName}...</info>");

            $result = $this->installPlugin($io, $pluginName, $pluginData, null, $options, $installer, $registry);

            if ($result === static::CODE_SUCCESS) {
                $totalSuccess++;
            } else {
                $totalErrors++;
            }
        }

        $io->out('');
        $io->out('<info>Summary:</info>');
        $io->out('  Plugins processed: ' . count($plugins));
        $io->out("  Successful: {$totalSuccess}");
        $io->out("  Errors: {$totalErrors}");

        return $totalErrors > 0 ? static::CODE_ERROR : static::CODE_SUCCESS;
    }

    /**
     * Install assets from a single plugin
     *
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @param string $pluginName Plugin name
     * @param array<string, mixed> $pluginData Plugin data
     * @param string|null $tagFilter Tag filter
     * @param array<string, mixed> $options Install options
     * @param \Crustum\PluginManifest\Manifest\Installer $installer Installer instance
     * @return int Exit code
     */
    protected function installPlugin(
        ConsoleIo $io,
        string $pluginName,
        array $pluginData,
        ?string $tagFilter,
        array $options,
        Installer $installer,
        ManifestRegistry $registry,
    ): int {
        $successCount = 0;
        $skipCount = 0;
        $errorCount = 0;
        $dryRun = $options['dry_run'] ?? false;

        $assets = $pluginData['assets'];

        if ($tagFilter && !isset($assets[$tagFilter])) {
            $io->error("Tag '{$tagFilter}' not found in {$pluginName}.");
            $io->out('Available tags: ' . implode(', ', array_keys($assets)));

            return static::CODE_ERROR;
        }

        $tagsToInstall = $tagFilter ? [$tagFilter => $assets[$tagFilter]] : $assets;

        foreach ($tagsToInstall as $tag => $tagAssets) {
            $io->out('');
            $io->out("<comment>Tag: {$tag}</comment>");

            foreach ($tagAssets as $asset) {
                $result = $installer->install($asset, $options);

                $this->displayResult($io, $result);

                if ($result->success) {
                    $successCount++;

                    if (!$dryRun) {
                        if ($result->getBatchResults() !== null) {
                            foreach ($result->getBatchResults() as $batchResult) {
                                if ($batchResult->success) {
                                    $assetData = [
                                        'destination' => $batchResult->destination,
                                        'source' => $batchResult->source,
                                        'completed' => true,
                                    ];

                                    $registry->recordInstalled(
                                        $pluginName,
                                        $asset['type'],
                                        $tag,
                                        $assetData,
                                    );
                                }
                            }
                        } else {
                            $assetData = [
                                'destination' => $result->destination ?? $asset['destination'] ?? null,
                                'completed' => true,
                            ];

                            if (isset($asset['source'])) {
                                $assetData['source'] = $asset['source'];
                            }
                            if (isset($asset['marker'])) {
                                $assetData['marker'] = $asset['marker'];
                            }
                            if (isset($asset['key'])) {
                                $assetData['key'] = $asset['key'];
                            }

                            $registry->recordInstalled(
                                $pluginName,
                                $asset['type'],
                                $tag,
                                $assetData,
                            );
                        }
                    }
                } elseif ($result->status === 'skipped') {
                    $skipCount++;
                } else {
                    $errorCount++;
                }
            }
        }

        $io->out('');
        $io->out("<info>{$pluginName} Installation Summary:</info>");
        $io->out("  Installed: {$successCount}");
        $io->out("  Skipped: {$skipCount}");
        $io->out("  Errors: {$errorCount}");

        return $errorCount > 0 ? static::CODE_ERROR : static::CODE_SUCCESS;
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
    protected function truncatePath(string $path, int $maxLength): string
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
