<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Command;

use Cake\Command\Command;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Plugin;
use Crustum\PluginManifest\Manifest\ManifestRegistry;
use Crustum\PluginManifest\Manifest\ManifestResolver;
use Override;

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
        return $parser;
    }

    /**
     * @inheritDoc
     */
    #[Override]
    public static function getDescription(): string
    {
        return 'List all plugins with publishable assets and their installation status';
    }

    /**
     * Execute command
     *
     * @return int|null Exit code
     */
    public function execute(): ?int
    {
        $manifest = new ManifestRegistry();
        $plugins = $this->discoverPublishablePlugins();
        if ($plugins === []) {
            $this->io->warning('No plugins with publishable assets found.');

            return static::CODE_SUCCESS;
        }

        $this->io->out('<info>Available Plugins with Publishable Assets:</info>');
        $this->io->out('');
        foreach ($plugins as $pluginName => $pluginData) {
            $this->displayPluginInfo($pluginName, $pluginData, $manifest);
        }

        return static::CODE_SUCCESS;
    }

    /**
     * Discover plugins that provide a manifest.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function discoverPublishablePlugins(): array
    {
        $plugins = [];
        $loadedPlugins = Plugin::loaded();

        foreach ($loadedPlugins as $pluginName) {
            $plugin = Plugin::getCollection()->get($pluginName);
            $pluginClass = $plugin::class;

            $manifest = ManifestResolver::manifestFor($pluginClass);
            if ($manifest === null) {
                continue;
            }

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

            $organized[$tag] ??= [];

            $organized[$tag][] = $asset;
        }

        return $organized;
    }

    /**
     * Display plugin information
     *
     * @param string $pluginName Plugin name
     * @param array<string, mixed> $pluginData Plugin data
     * @param \Crustum\PluginManifest\Manifest\ManifestRegistry $manifest Registry
     * @return void
     */
    protected function displayPluginInfo(string $pluginName, array $pluginData, ManifestRegistry $manifest): void
    {
        $this->io->out("<success>{$pluginName}</success>");

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

            $this->io->out("  {$status} {$tag}: {$tagInstalled}/{$tagTotal} installed");
        }

        $totalStatus = $installedAssets === $totalAssets ? 'All installed' : "{$installedAssets}/{$totalAssets} installed";
        $this->io->out("  <comment>Total: {$totalStatus}</comment>");
        $this->io->out('');
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
            if ($file === '.') {
                continue;
            }

            if ($file === '..') {
                continue;
            }

            if ($file === 'schema-dump-default.lock') {
                continue;
            }

            if (preg_match('/^\d{14}_.*\.php$/', $file)) {
                $count++;
            }
        }

        return ['total' => max(1, $count)];
    }
}
