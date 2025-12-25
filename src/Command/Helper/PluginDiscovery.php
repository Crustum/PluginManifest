<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Command\Helper;

use Cake\Console\ConsoleIo;
use Cake\Core\Plugin;
use Crustum\PluginManifest\Manifest\ManifestInterface;
use Exception;

/**
 * Helper for discovering and organizing plugin assets
 */
class PluginDiscovery
{
    /**
     * Discover plugins that implement ManifestInterface
     *
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @return array<string, array<string, mixed>> Plugin data keyed by plugin name
     */
    public function discoverPublishablePlugins(ConsoleIo $io): array
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
    public function organizeAssetsByTag(array $assets, string $pluginName): array
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
