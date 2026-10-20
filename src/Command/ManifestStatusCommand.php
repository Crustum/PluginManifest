<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Command;

use Cake\Command\Command;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Plugin;
use Crustum\PluginManifest\Manifest\ManifestRegistry;
use Crustum\PluginManifest\Manifest\ManifestResolver;
use Exception;
use Override;

/**
 * ManifestStatus command
 *
 * Shows installation status of plugin assets and dependencies
 */
class ManifestStatusCommand extends Command
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
                'help' => 'The plugin to show status for',
            ])
            ->addOption('dependencies', [
                'short' => 'd',
                'help' => 'Show dependency installation status',
                'boolean' => true,
            ])
            ->addOption('all', [
                'short' => 'a',
                'help' => 'Show status for all plugins',
                'boolean' => true,
            ]);
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public static function getDescription(): string
    {
        return 'Show plugin asset installation status';
    }

    /**
     * Implement this method with your command's logic
     *
     * @return int|null The exit code or null for success
     */
    public function execute(): ?int
    {
        $pluginFilter = $this->args->getOption('plugin');
        $showDependencies = (bool)$this->args->getOption('dependencies');
        $all = (bool)$this->args->getOption('all');
        $registry = new ManifestRegistry();
        if ($all) {
            return $this->showAllPluginStatus($showDependencies, $registry);
        }

        if (!$pluginFilter) {
            $this->io->error('Please specify --plugin or use --all');

            return static::CODE_ERROR;
        }

        $pluginName = (string)$pluginFilter;
        try {
            Plugin::getCollection()->get($pluginName);
        } catch (Exception) {
            $this->io->error("Plugin '{$pluginName}' not found.");

            return static::CODE_ERROR;
        }

        return $this->showPluginStatus($pluginName, $showDependencies, $registry);
    }

    /**
     * Show status for all plugins
     *
     * @param bool $showDependencies Show dependencies
     * @param \Crustum\PluginManifest\Manifest\ManifestRegistry $registry Registry
     * @return int Exit code
     */
    protected function showAllPluginStatus(
        bool $showDependencies,
        ManifestRegistry $registry,
    ): int {
        $this->io->out('<info>Plugin Installation Status:</info>');
        $this->io->out('');

        $allStatuses = $registry->getAllPluginStatuses();

        if ($allStatuses === []) {
            $this->io->info('No plugins have installed assets.');

            return static::CODE_SUCCESS;
        }

        foreach ($allStatuses as $pluginName => $status) {
            $icon = $status['installed'] ? '[+]' : '[-]';
            $statusLabel = $status['installed'] ? '<success>installed</success>' : '<error>not installed</error>';

            $this->io->out("<warning>{$pluginName}</warning> {$icon} {$statusLabel}");

            if ($showDependencies && !empty($status['dependencies'])) {
                $this->io->out('  Dependencies:');
                foreach ($status['dependencies'] as $depName => $depConfig) {
                    $installedAt = $depConfig['installed_at'] ?? 'unknown';
                    $this->io->out("    ├── {$depName} (installed: {$installedAt})");
                }
            }

            $this->io->out('');
        }

        return static::CODE_SUCCESS;
    }

    /**
     * Show status for specific plugin
     *
     * @param string $pluginName Plugin name
     * @param bool $showDependencies Show dependencies
     * @param \Crustum\PluginManifest\Manifest\ManifestRegistry $registry Registry
     * @return int Exit code
     */
    protected function showPluginStatus(
        string $pluginName,
        bool $showDependencies,
        ManifestRegistry $registry,
    ): int {
        $status = $registry->getPluginStatus($pluginName);

        $this->io->out("<info>{$pluginName} Installation Status:</info>");
        $this->io->out('');

        if (!$status['installed']) {
            $this->io->warning('No assets installed for this plugin.');

            if ($showDependencies) {
                $this->io->out('');
                $this->io->info('No dependencies installed.');
            }

            return static::CODE_SUCCESS;
        }

        $this->io->out('<success>Assets Installed:</success>');
        foreach ($status['operations'] as $operation => $tags) {
            $this->io->out("  Operation: {$operation}");
            foreach ($tags as $tag => $assets) {
                $count = count($assets);
                $this->io->out("    ├── Tag '{$tag}': {$count} asset(s)");
            }
        }

        if ($showDependencies) {
            $this->io->out('');
            if (empty($status['dependencies'])) {
                $this->io->info('No dependencies installed.');
            } else {
                $this->io->out('<success>Dependencies Installed:</success>');
                foreach ($status['dependencies'] as $depName => $depConfig) {
                    $installedAt = $depConfig['installed_at'] ?? 'unknown';
                    $required = $depConfig['required'] ?? false;
                    $typeLabel = $required ? 'required' : 'optional';

                    $this->io->out("  ├── <warning>{$depName}</warning> ({$typeLabel})");
                    $this->io->out("  │   Installed: {$installedAt}");

                    if (isset($depConfig['tags'])) {
                        $tags = is_array($depConfig['tags']) ? implode(', ', $depConfig['tags']) : 'all';
                        $this->io->out("  │   Tags: {$tags}");
                    }
                }
            }

            if (!empty($status['depended_by'])) {
                $this->io->out('');
                $this->io->out('<warning>Used By:</warning>');
                foreach ($status['depended_by'] as $dependent) {
                    $this->io->out("  ├── {$dependent}");
                }
            }
        }

        return static::CODE_SUCCESS;
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
