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

class ManifestListCommand extends Command
{
    /**
     * Build option parser
     *
     * @param \Cake\Console\ConsoleOptionParser $parser Option parser
     * @return \Cake\Console\ConsoleOptionParser
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser->setDescription('List all plugins with publishable assets and their installation status');

        return $parser;
    }

    /**
     * Execute command
     *
     * @param \Cake\Console\Arguments $args Arguments
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @return int|null Exit code
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $manifest = new ManifestRegistry();
        $plugins = $this->discoverPublishablePlugins();

        if (empty($plugins)) {
            $io->warning('No plugins with publishable assets found.');

            return static::CODE_SUCCESS;
        }

        $io->out('<info>Available Plugins with Publishable Assets:</info>');
        $io->out('');

        foreach ($plugins as $pluginName => $pluginData) {
            $this->displayPluginInfo($io, $pluginName, $pluginData, $manifest);
        }

        return static::CODE_SUCCESS;
    }

    /**
     * Discover plugins that implement ManifestInterface
     *
     * @return array<string, array<string, mixed>>
     */
    protected function discoverPublishablePlugins(): array
    {
        $plugins = [];
        $loadedPlugins = Plugin::loaded();

        foreach ($loadedPlugins as $pluginName) {
            $plugin = Plugin::getCollection()->get($pluginName);
            $pluginClass = get_class($plugin);

            if (!is_subclass_of($pluginClass, ManifestInterface::class)) {
                continue;
            }

            $manifest = $pluginClass::manifest();
            $assets = $this->organizeAssetsByTag($manifest);

            $plugins[$pluginName] = [
                'class' => $pluginClass,
                'assets' => $assets,
            ];
        }

        return $plugins;
    }

    /**
     * Organize assets by tag
     *
     * @param array<int, array<string, mixed>> $manifest Manifest data
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function organizeAssetsByTag(array $manifest): array
    {
        $organized = [];

        foreach ($manifest as $asset) {
            $tag = $asset['tag'] ?? 'default';

            if (!isset($organized[$tag])) {
                $organized[$tag] = [];
            }

            $organized[$tag][] = $asset;
        }

        return $organized;
    }

    /**
     * Display plugin information
     *
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @param string $pluginName Plugin name
     * @param array<string, mixed> $pluginData Plugin data
     * @param \Crustum\PluginManifest\Manifest\ManifestRegistry $manifest Registry
     * @return void
     */
    protected function displayPluginInfo(ConsoleIo $io, string $pluginName, array $pluginData, ManifestRegistry $manifest): void
    {
        $io->out("<success>{$pluginName}</success>");

        $assets = $pluginData['assets'];
        $totalAssets = 0;
        $installedAssets = 0;

        foreach ($assets as $tag => $tagAssets) {
            $tagTotal = 0;
            $tagInstalled = 0;

            foreach ($tagAssets as $asset) {
                $counts = $this->countAssetFiles($asset);
                $tagTotal += $counts['total'];

                $installed = $manifest->getInstalled($pluginName, $asset['type'], $tag);
                $tagInstalled += count($installed);
            }

            $totalAssets += $tagTotal;
            $installedAssets += $tagInstalled;

            if ($tagInstalled === $tagTotal) {
                $status = '<success>[+]</success>';
            } elseif ($tagInstalled > 0) {
                $status = '<warning>[~]</warning>';
            } else {
                $status = '<warning>[ ]</warning>';
            }

            $io->out("  {$status} {$tag}: {$tagInstalled}/{$tagTotal} installed");
        }

        $totalStatus = $installedAssets === $totalAssets ? 'All installed' : "{$installedAssets}/{$totalAssets} installed";
        $io->out("  <comment>Total: {$totalStatus}</comment>");
        $io->out('');
    }

    /**
     * Count files in asset (1 for file, N for directory)
     *
     * @param array<string, mixed> $asset Asset data
     * @return array<string, int>
     */
    protected function countAssetFiles(array $asset): array
    {
        $source = $asset['source'] ?? '';

        if (empty($source) || !file_exists($source)) {
            return ['total' => 1];
        }

        if (!is_dir($source)) {
            return ['total' => 1];
        }

        $files = scandir($source);
        $count = 0;

        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || $file === 'schema-dump-default.lock') {
                continue;
            }

            if (preg_match('/^\d{14}_.*\.php$/', $file)) {
                $count++;
            }
        }

        return ['total' => max(1, $count)];
    }
}
