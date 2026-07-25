<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Command\Helper;

use Cake\Console\ConsoleIo;
use Crustum\PluginManifest\Manifest\Installer;
use Crustum\PluginManifest\Manifest\InstallResult;
use Crustum\PluginManifest\Manifest\ManifestRegistry;
use Crustum\PluginManifest\Manifest\OperationType;
use Crustum\PluginManifest\Manifest\Tag;

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
        $selections = [];
        foreach (array_keys($plugins) as $pluginName) {
            $selections[] = [
                'plugin' => $pluginName,
                'tags' => null,
            ];
        }

        return $this->installSelections($io, $plugins, $selections, $options);
    }

    /**
     * Install assets for interactive or batch selections.
     *
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @param array<string, array<string, mixed>> $plugins Plugin data
     * @param list<array{plugin: string, tags: list<string>|null}> $selections Targets
     * @param array<string, mixed> $options Install options
     * @return int Exit code
     */
    public function installSelections(ConsoleIo $io, array $plugins, array $selections, array $options): int
    {
        $totalSuccess = 0;
        $totalErrors = 0;

        foreach ($selections as $selection) {
            $pluginName = $selection['plugin'];
            if (!isset($plugins[$pluginName])) {
                $io->error("Plugin '{$pluginName}' not found or does not implement ManifestInterface.");
                $totalErrors++;
                continue;
            }

            $result = $this->installPlugin(
                $io,
                $pluginName,
                $plugins[$pluginName],
                $selection['tags'],
                $options,
            );

            if ($result === 0) {
                $totalSuccess++;
            } else {
                $totalErrors++;
            }
        }

        if (count($selections) > 1) {
            $this->formatter->displayBatchSummary(
                $io,
                count($selections),
                $totalSuccess,
                $totalErrors,
            );
        }

        return $totalErrors > 0 ? 1 : 0;
    }

    /**
     * Install assets from a single plugin
     *
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @param string $pluginName Plugin name
     * @param array<string, mixed> $pluginData Plugin data
     * @param list<string>|string|null $tagFilter Tag filter (null = all tags)
     * @param array<string, mixed> $options Install options
     * @return int Exit code
     */
    public function installPlugin(
        ConsoleIo $io,
        string $pluginName,
        array $pluginData,
        array|string|null $tagFilter,
        array $options,
    ): int {
        $successCount = 0;
        $skipCount = 0;
        $errorCount = 0;
        $dryRun = $options['dry_run'] ?? false;

        $assets = $pluginData['assets'];

        if (is_string($tagFilter)) {
            $tagFilter = [$tagFilter];
        }

        if ($tagFilter !== null) {
            foreach ($tagFilter as $tag) {
                if (!isset($assets[$tag])) {
                    $io->error("Tag '{$tag}' not found in {$pluginName}.");
                    $io->out('Available tags: ' . implode(', ', array_keys($assets)));

                    return 1;
                }
            }

            $tagsToInstall = [];
            foreach ($tagFilter as $tag) {
                $tagsToInstall[$tag] = $assets[$tag];
            }
        } else {
            $tagsToInstall = $assets;
        }

        $displayTags = [];
        foreach (array_keys($tagsToInstall) as $tag) {
            if (is_string($tag) && $tag !== Tag::STAR_REPO) {
                $displayTags[] = $tag;
            }
        }

        $this->formatter->displayPluginIntro(
            $io,
            $pluginName,
            $tagFilter === null ? null : $displayTags,
        );

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
                $this->displayInstallResult($io, $result);
                $this->tallyResult($result, $successCount, $skipCount, $errorCount);

                if ($result->success && !$dryRun && ($asset['type'] ?? '') !== OperationType::DEPENDENCIES) {
                    $this->recordSuccessfulInstall($pluginName, (string)$tag, $asset, $result);
                }
            }
        }

        $this->formatter->displayPluginOutro(
            $io,
            $pluginName,
            $successCount,
            $skipCount,
            $errorCount,
        );

        if ($errorCount === 0 && $starRepoAsset !== null) {
            $this->starRepo->askToStarRepo($io, $pluginName, $starRepoAsset, $options);
        }

        return $errorCount > 0 ? 1 : 0;
    }

    /**
     * Print install result lines (expands batch results).
     *
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @param \Crustum\PluginManifest\Manifest\InstallResult $result Install result
     * @return void
     */
    protected function displayInstallResult(ConsoleIo $io, InstallResult $result): void
    {
        $batch = $result->getBatchResults();
        if ($batch !== null && $batch !== []) {
            foreach ($batch as $batchResult) {
                $this->formatter->displayResult($io, $batchResult);
            }

            return;
        }

        $this->formatter->displayResult($io, $result);
    }

    /**
     * Update counters from a result.
     *
     * @param \Crustum\PluginManifest\Manifest\InstallResult $result Install result
     * @param int $successCount Success counter
     * @param int $skipCount Skip counter
     * @param int $errorCount Error counter
     * @return void
     */
    protected function tallyResult(
        InstallResult $result,
        int &$successCount,
        int &$skipCount,
        int &$errorCount,
    ): void {
        if ($result->success) {
            $successCount++;
        } elseif ($result->status === 'skipped') {
            $skipCount++;
        } else {
            $errorCount++;
        }
    }

    /**
     * Record successful install into the registry.
     *
     * @param string $pluginName Plugin name
     * @param string $tag Tag
     * @param array<string, mixed> $asset Asset definition
     * @param \Crustum\PluginManifest\Manifest\InstallResult $result Install result
     * @return void
     */
    protected function recordSuccessfulInstall(
        string $pluginName,
        string $tag,
        array $asset,
        InstallResult $result,
    ): void {
        if ($result->getBatchResults() !== null) {
            foreach ($result->getBatchResults() as $batchResult) {
                if ($batchResult->success) {
                    $this->registry->recordInstalled(
                        $pluginName,
                        $asset['type'],
                        $tag,
                        [
                            'destination' => $batchResult->destination,
                            'source' => $batchResult->source,
                            'completed' => true,
                        ],
                    );
                }
            }

            return;
        }

        $assetData = [
            'destination' => $result->destination !== '' ? $result->destination : ($asset['destination'] ?? null),
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
