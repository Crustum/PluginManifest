<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Crustum\PluginManifest\Command\Helper\PluginDiscovery;
use Crustum\PluginManifest\Manifest\ManifestRegistry;
use Crustum\PluginManifest\Manifest\OperationType;
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
        return $parser
            ->addOption('path', [
                'help' => 'Additional plugin directory to scan for manifests (repeatable)',
                'multiple' => true,
            ]);
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
     * @param \Cake\Console\Arguments $args Arguments
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @return int|null Exit code
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $manifest = new ManifestRegistry();
        $plugins = $this->discoverPublishablePlugins(
            PluginDiscovery::normalizePaths($args->getOption('path')),
        );

        if ($plugins === []) {
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
     * Discover plugins that provide a manifest.
     *
     * @param list<string> $extraPaths Additional plugin directories to scan
     * @return array<string, array<string, mixed>>
     */
    protected function discoverPublishablePlugins(array $extraPaths = []): array
    {
        $plugins = [];
        $discovery = new PluginDiscovery();

        foreach ($discovery->discoverRaw($extraPaths) as $pluginName => $data) {
            $plugins[$pluginName] = [
                'class' => $data['class'],
                'loaded' => $data['loaded'],
                'assets' => $this->organizeAssetsByTag($data['manifest']),
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
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @param string $pluginName Plugin name
     * @param array<string, mixed> $pluginData Plugin data
     * @param \Crustum\PluginManifest\Manifest\ManifestRegistry $manifest Registry
     * @return void
     */
    protected function displayPluginInfo(ConsoleIo $io, string $pluginName, array $pluginData, ManifestRegistry $manifest): void
    {
        $loadedLabel = $pluginData['loaded'] ?? true ? '' : ' <warning>(not loaded)</warning>';
        $io->out("<success>{$pluginName}</success>{$loadedLabel}");

        $assets = $pluginData['assets'];
        $totalAssets = 0;
        $installedAssets = 0;

        foreach ($assets as $tag => $tagAssets) {
            $summary = $this->summarizeTag($tag, $tagAssets, $pluginName, $manifest);
            if ($summary === null) {
                continue;
            }

            [$tagTotal, $tagInstalled] = [$summary['total'], $summary['installed']];
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
     * Summarize installation progress for one tag.
     *
     * Star-repo prompts are display noise, not installable files, so they
     * are excluded entirely. Installed records are counted once per
     * operation type: the registry tracks whole (plugin, type, tag)
     * buckets, so counting per asset would multiply the result.
     *
     * @param string $tag Tag name
     * @param array<int, array<string, mixed>> $tagAssets Assets of one tag
     * @param string $pluginName Plugin name
     * @param \Crustum\PluginManifest\Manifest\ManifestRegistry $manifest Registry
     * @return array{installed: int, total: int}|null Null when nothing countable remains
     */
    protected function summarizeTag(string $tag, array $tagAssets, string $pluginName, ManifestRegistry $manifest): ?array
    {
        $total = 0;
        $installed = 0;
        $countedTypes = [];

        foreach ($tagAssets as $asset) {
            $type = $asset['type'] ?? 'unknown';
            if ($type === OperationType::STAR_REPO) {
                continue;
            }

            $total += $this->countAssetFiles($asset)['total'];

            if (!isset($countedTypes[$type])) {
                $countedTypes[$type] = true;
                $installed += count($manifest->getInstalled($pluginName, $type, $tag));
            }
        }

        if ($total === 0 && $installed === 0) {
            return null;
        }

        return ['installed' => $installed, 'total' => $total];
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
