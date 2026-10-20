<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Command\Helper;

use Cake\Console\ConsoleIoInterface;
use Cake\Core\Plugin;
use Crustum\PluginManifest\Manifest\ManifestResolver;
use Exception;

/**
 * Helper for discovering and organizing plugin assets
 */
class PluginDiscovery
{
    /**
     * Discover plugins that provide a manifest.
     *
     * Covers both the {@see ManifestInterface} contract and standalone
     * provider classes (see {@see ManifestResolver}).
     *
     * @param \Cake\Console\ConsoleIoInterface $io Console IO
     * @return array<string, array<string, mixed>> Plugin data keyed by plugin name
     */
    public function discoverPublishablePlugins(ConsoleIoInterface $io): array
    {
        $plugins = [];

        foreach (Plugin::loaded() as $pluginName) {
            $plugin = Plugin::getCollection()->get($pluginName);
            $pluginClass = $plugin::class;

            $assets = ManifestResolver::manifestFor($pluginClass);
            if ($assets === null) {
                continue;
            }

            if ($assets === []) {
                continue;
            }

            try {
                $plugins[$pluginName] = [
                    'class' => $pluginClass,
                    'assets' => $this->organizeAssetsByTag($assets, $pluginName),
                ];
            } catch (Exception $e) {
                $io->warning("Failed to get assets from {$pluginName}: " . $e->getMessage());
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
    public function organizeAssetsByTag(array $assets, string $pluginName): array
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
