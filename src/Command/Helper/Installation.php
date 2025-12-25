<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Command\Helper;

use Cake\Console\ConsoleIo;
use Crustum\PluginManifest\Manifest\Installer;
use Crustum\PluginManifest\Manifest\ManifestRegistry;
use Crustum\PluginManifest\Manifest\OperationType;

/**
 * Helper for installing plugin assets
 */
class Installation
{
    /**
     * Constructor
     *
     * @param \Crustum\PluginManifest\Manifest\Installer $installer Installer instance
     * @param \Crustum\PluginManifest\Manifest\ManifestRegistry $registry Registry
     * @param \Crustum\PluginManifest\Command\Helper\OutputFormatter $formatter Output formatter
     * @param \Crustum\PluginManifest\Command\Helper\StarRepo $starRepo Star repo helper
     */
    public function __construct(
        private Installer $installer,
        private ManifestRegistry $registry,
        private OutputFormatter $formatter,
        private StarRepo $starRepo,
    ) {
    }

    /**
     * Install assets from all plugins
     *
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @param array<string, array<string, mixed>> $plugins Plugin data
     * @param array<string, mixed> $options Install options
     * @return int Exit code
     */
    public function installAllPlugins(ConsoleIo $io, array $plugins, array $options): int
    {
        $totalSuccess = 0;
        $totalErrors = 0;

        foreach ($plugins as $pluginName => $pluginData) {
            $io->out('');
            $io->out("<info>Installing assets from {$pluginName}...</info>");

            $result = $this->installPlugin($io, $pluginName, $pluginData, null, $options);

            if ($result === 0) {
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

        return $totalErrors > 0 ? 1 : 0;
    }

    /**
     * Install assets from a single plugin
     *
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @param string $pluginName Plugin name
     * @param array<string, mixed> $pluginData Plugin data
     * @param string|null $tagFilter Tag filter
     * @param array<string, mixed> $options Install options
     * @return int Exit code
     */
    public function installPlugin(
        ConsoleIo $io,
        string $pluginName,
        array $pluginData,
        ?string $tagFilter,
        array $options,
    ): int {
        $successCount = 0;
        $skipCount = 0;
        $errorCount = 0;
        $dryRun = $options['dry_run'] ?? false;

        $assets = $pluginData['assets'];

        if ($tagFilter && !isset($assets[$tagFilter])) {
            $io->error("Tag '{$tagFilter}' not found in {$pluginName}.");
            $io->out('Available tags: ' . implode(', ', array_keys($assets)));

            return 1;
        }

        $tagsToInstall = $tagFilter ? [$tagFilter => $assets[$tagFilter]] : $assets;
        $starRepoAsset = null;

        foreach ($assets as $tag => $tagAssets) {
            foreach ($tagAssets as $asset) {
                if (($asset['type'] ?? '') === OperationType::STAR_REPO) {
                    $starRepoAsset = $asset;
                    break 2;
                }
            }
        }

        foreach ($tagsToInstall as $tag => $tagAssets) {
            $hasNonStarRepoAssets = false;
            foreach ($tagAssets as $asset) {
                if (($asset['type'] ?? '') !== OperationType::STAR_REPO) {
                    $hasNonStarRepoAssets = true;
                    break;
                }
            }

            if ($hasNonStarRepoAssets) {
                $io->out('');
                $io->out("<comment>Tag: {$tag}</comment>");
            }

            foreach ($tagAssets as $asset) {
                if (($asset['type'] ?? '') === OperationType::STAR_REPO) {
                    $starRepoAsset = $asset;
                    continue;
                }

                $result = $this->installer->install($asset, $options);

                $this->formatter->displayResult($io, $result);

                if ($result->success) {
                    $successCount++;

                    if (!$dryRun && ($asset['type'] ?? '') !== OperationType::DEPENDENCIES) {
                        if ($result->getBatchResults() !== null) {
                            foreach ($result->getBatchResults() as $batchResult) {
                                if ($batchResult->success) {
                                    $assetData = [
                                        'destination' => $batchResult->destination,
                                        'source' => $batchResult->source,
                                        'completed' => true,
                                    ];

                                    $this->registry->recordInstalled(
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

                            $this->registry->recordInstalled(
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

        if ($errorCount === 0 && $starRepoAsset !== null) {
            $this->starRepo->askToStarRepo($io, $pluginName, $starRepoAsset, $options);
        }

        return $errorCount > 0 ? 1 : 0;
    }
}
