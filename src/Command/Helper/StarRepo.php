<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Command\Helper;

use Cake\Console\ConsoleIo;
use Cake\Core\Plugin;
use Crustum\PluginManifest\Manifest\GitHubRepoResolver;
use Crustum\PluginManifest\Manifest\ManifestRegistry;

/**
 * Helper for handling GitHub star repository prompts
 */
class StarRepo
{
    /**
     * Constructor
     *
     * @param \Crustum\PluginManifest\Manifest\ManifestRegistry $registry Registry
     */
    public function __construct(
        private ManifestRegistry $registry,
    ) {
    }

    /**
     * Ask user to star repository on GitHub if applicable
     *
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @param string $pluginName Plugin name
     * @param array<string, mixed> $starRepoAsset Star repo asset from manifest
     * @param array<string, mixed> $options Command options
     * @return void
     */
    public function askToStarRepo(
        ConsoleIo $io,
        string $pluginName,
        array $starRepoAsset,
        array $options,
    ): void {
        if ($options['dry_run'] ?? false) {
            return;
        }

        if ($options['all'] ?? false) {
            return;
        }

        if ($options['all_deps'] ?? false) {
            return;
        }

        if ($this->registry->hasStarPromptBeenShown($pluginName)) {
            return;
        }

        $githubRepo = $starRepoAsset['repo'] ?? null;
        $defaultAnswer = $starRepoAsset['default_answer'] ?? true;

        if (!$githubRepo) {
            $plugin = Plugin::getCollection()->get($pluginName);
            $pluginPath = $plugin->getPath();
            $githubRepo = GitHubRepoResolver::getFromPluginPath($pluginPath);
        }

        if (!$githubRepo) {
            return;
        }

        $io->out('');
        $confirmed = $io->askChoice(
            "Would you like to star {$githubRepo} on GitHub?",
            ['y', 'n'],
            $defaultAnswer ? 'y' : 'n',
        );

        if ($confirmed === 'y') {
            $repoUrl = "https://github.com/{$githubRepo}";

            $os = PHP_OS_FAMILY;
            if ($os === 'Darwin') {
                exec("open {$repoUrl}");
            } elseif ($os === 'Windows') {
                exec("start {$repoUrl}");
            } elseif ($os === 'Linux') {
                exec("xdg-open {$repoUrl}");
            }

            $io->success("Opening {$repoUrl} in your browser...");
        }

        $this->registry->recordStarPromptShown($pluginName);
    }
}
