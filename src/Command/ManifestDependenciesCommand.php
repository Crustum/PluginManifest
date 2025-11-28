<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Plugin;
use Crustum\PluginManifest\Manifest\DependencyResolver;
use Crustum\PluginManifest\Manifest\ManifestInterface;
use Crustum\PluginManifest\Manifest\ManifestRegistry;
use Exception;
use InvalidArgumentException;

/**
 * ManifestDependencies command
 *
 * Shows dependency tree and status for plugins
 */
class ManifestDependenciesCommand extends Command
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
            ->setDescription('Show plugin dependency tree and status')
            ->addOption('plugin', [
                'short' => 'p',
                'help' => 'The plugin to show dependencies for',
            ])
            ->addOption('status', [
                'short' => 's',
                'help' => 'Show installation status of dependencies',
                'boolean' => true,
            ])
            ->addOption('tree', [
                'short' => 't',
                'help' => 'Show full dependency tree (recursive)',
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
        $pluginFilter = $args->getOption('plugin');
        $showStatus = (bool)$args->getOption('status');
        $showTree = (bool)$args->getOption('tree');

        if (!$pluginFilter) {
            return $this->showAllPluginDependencies($io, $showStatus);
        }

        $pluginName = (string)$pluginFilter;

        try {
            $plugin = Plugin::getCollection()->get($pluginName);
        } catch (Exception $e) {
            $io->error("Plugin '{$pluginName}' not found.");

            return static::CODE_ERROR;
        }

        $pluginClass = get_class($plugin);
        if (!is_subclass_of($pluginClass, ManifestInterface::class)) {
            $io->error("Plugin '{$pluginName}' does not implement ManifestInterface.");

            return static::CODE_ERROR;
        }

        if ($showTree) {
            return $this->showDependencyTree($pluginName, $io, $showStatus);
        }

        return $this->showDirectDependencies($pluginName, $pluginClass, $io, $showStatus);
    }

    /**
     * Show all plugins with dependencies
     *
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @param bool $showStatus Show installation status
     * @return int Exit code
     */
    protected function showAllPluginDependencies(ConsoleIo $io, bool $showStatus): int
    {
        $io->out('<info>Plugins with dependencies:</info>');
        $io->out('');

        $found = false;
        foreach (Plugin::loaded() as $pluginName) {
            $plugin = Plugin::getCollection()->get($pluginName);
            $pluginClass = get_class($plugin);

            if (is_subclass_of($pluginClass, ManifestInterface::class)) {
                $assets = $pluginClass::manifest();
                $hasDeps = false;

                foreach ($assets as $asset) {
                    if (($asset['type'] ?? '') === 'dependencies') {
                        $hasDeps = true;
                        break;
                    }
                }

                if ($hasDeps) {
                    $found = true;
                    $io->out("<warning>{$pluginName}</warning>");

                    if ($showStatus) {
                        $this->showPluginDependencyStatus($pluginName, $io);
                    }
                }
            }
        }

        if (!$found) {
            $io->info('No plugins with dependencies found.');
        }

        return static::CODE_SUCCESS;
    }

    /**
     * Show direct dependencies for a plugin
     *
     * @param string $pluginName Plugin name
     * @param string $pluginClass Plugin class name
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @param bool $showStatus Show installation status
     * @return int Exit code
     */
    protected function showDirectDependencies(
        string $pluginName,
        string $pluginClass,
        ConsoleIo $io,
        bool $showStatus,
    ): int {
        $io->out("<info>{$pluginName} Dependencies:</info>");
        $io->out('');

        $assets = $pluginClass::manifest();
        $dependencyAsset = null;

        foreach ($assets as $asset) {
            if (($asset['type'] ?? '') === 'dependencies') {
                $dependencyAsset = $asset;
                break;
            }
        }

        if ($dependencyAsset === null) {
            $io->info('No dependencies declared.');

            return static::CODE_SUCCESS;
        }

        $dependencies = $dependencyAsset['dependencies'] ?? [];
        if (empty($dependencies)) {
            $io->info('No dependencies declared.');

            return static::CODE_SUCCESS;
        }

        $registry = new ManifestRegistry();

        foreach ($dependencies as $depName => $config) {
            $required = $config['required'] ?? false;
            $tags = $config['tags'] ?? ['all'];
            $reason = $config['reason'] ?? 'No reason provided';

            $typeLabel = $required ? 'required' : 'optional';
            $tagList = is_array($tags) ? implode(', ', $tags) : 'all';

            if ($showStatus) {
                $status = $registry->getPluginStatus($depName);
                $installed = $status['installed'];
                $icon = $installed ? '✓' : '✗';
                $statusLabel = $installed ? '<success>installed</success>' : '<error>not installed</error>';

                $io->out("├── <warning>{$depName}</warning> ({$typeLabel}) {$icon} {$statusLabel}");
                $io->out("│   Tags: {$tagList}");
                $io->out("│   Reason: {$reason}");
            } else {
                $io->out("├── <warning>{$depName}</warning> ({$typeLabel})");
                $io->out("│   Tags: {$tagList}");
                $io->out("│   Reason: {$reason}");
            }

            $io->out('');
        }

        return static::CODE_SUCCESS;
    }

    /**
     * Show complete dependency tree for a plugin
     *
     * @param string $pluginName Plugin name
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @param bool $showStatus Show installation status
     * @return int Exit code
     */
    protected function showDependencyTree(string $pluginName, ConsoleIo $io, bool $showStatus): int
    {
        $io->out("<info>{$pluginName} Complete Dependency Tree:</info>");
        $io->out('');

        $availablePlugins = $this->discoverAvailablePlugins();
        $resolver = new DependencyResolver();

        try {
            $tree = $resolver->buildDependencyTree(
                $availablePlugins,
                [$pluginName],
                ['force_all' => true],
            );
        } catch (InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return static::CODE_ERROR;
        }

        if (empty($tree)) {
            $io->info('No dependencies found.');

            return static::CODE_SUCCESS;
        }

        $registry = new ManifestRegistry();
        $level = 0;

        foreach (array_keys($tree) as $index => $depName) {
            $indent = str_repeat('  ', $level);

            if ($showStatus) {
                $status = $registry->getPluginStatus($depName);
                $installed = $status['installed'];
                $icon = $installed ? '✓' : '✗';
                $statusLabel = $installed ? '<success>installed</success>' : '<error>not installed</error>';

                $io->out("{$indent}" . ($index + 1) . ". <warning>{$depName}</warning> {$icon} {$statusLabel}");
            } else {
                $io->out("{$indent}" . ($index + 1) . ". <warning>{$depName}</warning>");
            }
        }

        return static::CODE_SUCCESS;
    }

    /**
     * Show plugin dependency status
     *
     * @param string $pluginName Plugin name
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @return void
     */
    protected function showPluginDependencyStatus(string $pluginName, ConsoleIo $io): void
    {
        $registry = new ManifestRegistry();
        $dependencies = $registry->getDependencies($pluginName);

        if (empty($dependencies)) {
            $io->out('  No dependencies installed');

            return;
        }

        foreach ($dependencies as $depName => $config) {
            $installedAt = $config['installed_at'] ?? 'unknown';
            $io->out("  ├── {$depName} (installed: {$installedAt})");
        }
    }

    /**
     * Discover available plugins that implement ManifestInterface
     *
     * @return array<string, array<string, mixed>> Plugin data keyed by plugin name
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
}
