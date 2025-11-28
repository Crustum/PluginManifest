<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Plugin;
use Crustum\PluginManifest\Manifest\ManifestInterface;
use Crustum\PluginManifest\Manifest\ManifestRegistry;
use Exception;

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
            ->setDescription('Show plugin asset installation status')
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
     * Implement this method with your command's logic
     *
     * @param \Cake\Console\Arguments $args The command arguments
     * @param \Cake\Console\ConsoleIo $io The console io
     * @return int|null The exit code or null for success
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $pluginFilter = $args->getOption('plugin');
        $showDependencies = (bool)$args->getOption('dependencies');
        $all = (bool)$args->getOption('all');

        $registry = new ManifestRegistry();

        if ($all) {
            return $this->showAllPluginStatus($io, $showDependencies, $registry);
        }

        if (!$pluginFilter) {
            $io->error('Please specify --plugin or use --all');

            return static::CODE_ERROR;
        }

        $pluginName = (string)$pluginFilter;

        try {
            Plugin::getCollection()->get($pluginName);
        } catch (Exception $e) {
            $io->error("Plugin '{$pluginName}' not found.");

            return static::CODE_ERROR;
        }

        return $this->showPluginStatus($pluginName, $io, $showDependencies, $registry);
    }

    /**
     * Show status for all plugins
     *
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @param bool $showDependencies Show dependencies
     * @param \Crustum\PluginManifest\Manifest\ManifestRegistry $registry Registry
     * @return int Exit code
     */
    protected function showAllPluginStatus(
        ConsoleIo $io,
        bool $showDependencies,
        ManifestRegistry $registry,
    ): int {
        $io->out('<info>Plugin Installation Status:</info>');
        $io->out('');

        $allStatuses = $registry->getAllPluginStatuses();

        if (empty($allStatuses)) {
            $io->info('No plugins have installed assets.');

            return static::CODE_SUCCESS;
        }

        foreach ($allStatuses as $pluginName => $status) {
            $icon = $status['installed'] ? '[+]' : '[-]';
            $statusLabel = $status['installed'] ? '<success>installed</success>' : '<error>not installed</error>';

            $io->out("<warning>{$pluginName}</warning> {$icon} {$statusLabel}");

            if ($showDependencies && !empty($status['dependencies'])) {
                $io->out('  Dependencies:');
                foreach ($status['dependencies'] as $depName => $depConfig) {
                    $installedAt = $depConfig['installed_at'] ?? 'unknown';
                    $io->out("    ├── {$depName} (installed: {$installedAt})");
                }
            }

            $io->out('');
        }

        return static::CODE_SUCCESS;
    }

    /**
     * Show status for specific plugin
     *
     * @param string $pluginName Plugin name
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @param bool $showDependencies Show dependencies
     * @param \Crustum\PluginManifest\Manifest\ManifestRegistry $registry Registry
     * @return int Exit code
     */
    protected function showPluginStatus(
        string $pluginName,
        ConsoleIo $io,
        bool $showDependencies,
        ManifestRegistry $registry,
    ): int {
        $status = $registry->getPluginStatus($pluginName);

        $io->out("<info>{$pluginName} Installation Status:</info>");
        $io->out('');

        if (!$status['installed']) {
            $io->warning('No assets installed for this plugin.');

            if ($showDependencies) {
                $io->out('');
                $io->info('No dependencies installed.');
            }

            return static::CODE_SUCCESS;
        }

        $io->out('<success>Assets Installed:</success>');
        foreach ($status['operations'] as $operation => $tags) {
            $io->out("  Operation: {$operation}");
            foreach ($tags as $tag => $assets) {
                $count = count($assets);
                $io->out("    ├── Tag '{$tag}': {$count} asset(s)");
            }
        }

        if ($showDependencies) {
            $io->out('');
            if (empty($status['dependencies'])) {
                $io->info('No dependencies installed.');
            } else {
                $io->out('<success>Dependencies Installed:</success>');
                foreach ($status['dependencies'] as $depName => $depConfig) {
                    $installedAt = $depConfig['installed_at'] ?? 'unknown';
                    $required = $depConfig['required'] ?? false;
                    $typeLabel = $required ? 'required' : 'optional';

                    $io->out("  ├── <warning>{$depName}</warning> ({$typeLabel})");
                    $io->out("  │   Installed: {$installedAt}");

                    if (isset($depConfig['tags'])) {
                        $tags = is_array($depConfig['tags']) ? implode(', ', $depConfig['tags']) : 'all';
                        $io->out("  │   Tags: {$tags}");
                    }
                }
            }

            if (!empty($status['depended_by'])) {
                $io->out('');
                $io->out('<warning>Used By:</warning>');
                foreach ($status['depended_by'] as $dependent) {
                    $io->out("  ├── {$dependent}");
                }
            }
        }

        return static::CODE_SUCCESS;
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
