<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Command;

use Cake\Command\Command;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Plugin;
use Crustum\PluginManifest\Manifest\DependencyResolver;
use Crustum\PluginManifest\Manifest\ManifestRegistry;
use Crustum\PluginManifest\Manifest\ManifestResolver;
use Exception;
use InvalidArgumentException;
use Override;

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
     * @inheritDoc
     */
    #[Override]
    public static function getDescription(): string
    {
        return 'Show plugin dependency tree and status';
    }

    /**
     * Implement this method with your command's logic
     *
     * @return int|null The exit code or null for success
     */
    public function execute(): ?int
    {
        $pluginFilter = $this->args->getOption('plugin');
        $showStatus = (bool)$this->args->getOption('status');
        $showTree = (bool)$this->args->getOption('tree');
        if (!$pluginFilter) {
            return $this->showAllPluginDependencies($showStatus);
        }

        $pluginName = (string)$pluginFilter;
        try {
            $plugin = Plugin::getCollection()->get($pluginName);
        } catch (Exception) {
            $this->io->error("Plugin '{$pluginName}' not found.");

            return static::CODE_ERROR;
        }

        $pluginClass = $plugin::class;
        $assets = ManifestResolver::manifestFor($pluginClass);
        if ($assets === null) {
            $this->io->error("Plugin '{$pluginName}' does not provide a manifest.");

            return static::CODE_ERROR;
        }

        if ($showTree) {
            return $this->showDependencyTree($pluginName, $showStatus);
        }

        return $this->showDirectDependencies($pluginName, $assets, $showStatus);
    }

    /**
     * Show all plugins with dependencies
     *
     * @param bool $showStatus Show installation status
     * @return int Exit code
     */
    protected function showAllPluginDependencies(bool $showStatus): int
    {
        $this->io->out('<info>Plugins with dependencies:</info>');
        $this->io->out('');

        $found = false;
        foreach (Plugin::loaded() as $pluginName) {
            $plugin = Plugin::getCollection()->get($pluginName);
            $pluginClass = $plugin::class;

            $assets = ManifestResolver::manifestFor($pluginClass);
            if ($assets !== null) {
                $hasDeps = false;

                foreach ($assets as $asset) {
                    if (($asset['type'] ?? '') === 'dependencies') {
                        $hasDeps = true;
                        break;
                    }
                }

                if ($hasDeps) {
                    $found = true;
                    $this->io->out("<warning>{$pluginName}</warning>");

                    if ($showStatus) {
                        $this->showPluginDependencyStatus($pluginName);
                    }
                }
            }
        }

        if (!$found) {
            $this->io->info('No plugins with dependencies found.');
        }

        return static::CODE_SUCCESS;
    }

    /**
     * Show direct dependencies for a plugin
     *
     * @param string $pluginName Plugin name
     * @param array<int, array<string, mixed>> $assets Resolved manifest operations
     * @param bool $showStatus Show installation status
     * @return int Exit code
     */
    protected function showDirectDependencies(
        string $pluginName,
        array $assets,
        bool $showStatus,
    ): int {
        $this->io->out("<info>{$pluginName} Dependencies:</info>");
        $this->io->out('');

        $dependencyAsset = null;

        foreach ($assets as $asset) {
            if (($asset['type'] ?? '') === 'dependencies') {
                $dependencyAsset = $asset;
                break;
            }
        }

        if ($dependencyAsset === null) {
            $this->io->info('No dependencies declared.');

            return static::CODE_SUCCESS;
        }

        $dependencies = $dependencyAsset['dependencies'] ?? [];
        if (empty($dependencies)) {
            $this->io->info('No dependencies declared.');

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

                $this->io->out("├── <warning>{$depName}</warning> ({$typeLabel}) {$icon} {$statusLabel}");
                $this->io->out("│   Tags: {$tagList}");
                $this->io->out("│   Reason: {$reason}");
            } else {
                $this->io->out("├── <warning>{$depName}</warning> ({$typeLabel})");
                $this->io->out("│   Tags: {$tagList}");
                $this->io->out("│   Reason: {$reason}");
            }

            $this->io->out('');
        }

        return static::CODE_SUCCESS;
    }

    /**
     * Show complete dependency tree for a plugin
     *
     * @param string $pluginName Plugin name
     * @param bool $showStatus Show installation status
     * @return int Exit code
     */
    protected function showDependencyTree(string $pluginName, bool $showStatus): int
    {
        $this->io->out("<info>{$pluginName} Complete Dependency Tree:</info>");
        $this->io->out('');

        $availablePlugins = $this->discoverAvailablePlugins();
        $resolver = new DependencyResolver();

        try {
            $tree = $resolver->buildDependencyTree(
                $availablePlugins,
                [$pluginName],
                ['force_all' => true],
            );
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->io->error($invalidArgumentException->getMessage());

            return static::CODE_ERROR;
        }

        if ($tree === []) {
            $this->io->info('No dependencies found.');

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

                $this->io->out("{$indent}" . ($index + 1) . ". <warning>{$depName}</warning> {$icon} {$statusLabel}");
            } else {
                $this->io->out("{$indent}" . ($index + 1) . ". <warning>{$depName}</warning>");
            }
        }

        return static::CODE_SUCCESS;
    }

    /**
     * Show plugin dependency status
     *
     * @param string $pluginName Plugin name
     * @return void
     */
    protected function showPluginDependencyStatus(string $pluginName): void
    {
        $registry = new ManifestRegistry();
        $dependencies = $registry->getDependencies($pluginName);

        if ($dependencies === []) {
            $this->io->out('  No dependencies installed');

            return;
        }

        foreach ($dependencies as $depName => $config) {
            $installedAt = $config['installed_at'] ?? 'unknown';
            $this->io->out("  ├── {$depName} (installed: {$installedAt})");
        }
    }

    /**
     * Discover available plugins that provide a manifest.
     *
     * @return array<string, array<string, mixed>> Plugin data keyed by plugin name
     */
    protected function discoverAvailablePlugins(): array
    {
        $plugins = [];

        foreach (Plugin::loaded() as $pluginName) {
            $plugin = Plugin::getCollection()->get($pluginName);
            $pluginClass = $plugin::class;

            $assets = ManifestResolver::manifestFor($pluginClass);
            if (!empty($assets)) {
                $plugins[$pluginName] = [
                    'class' => $pluginClass,
                    'assets' => $this->organizeAssetsByTag($assets, $pluginName),
                ];
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
            $organized[$tag] ??= [];

            $asset['plugin'] = $pluginName;
            $organized[$tag][] = $asset;
        }

        return $organized;
    }
}
