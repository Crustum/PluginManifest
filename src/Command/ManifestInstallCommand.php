<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Crustum\PluginManifest\Command\Helper\Installation;
use Crustum\PluginManifest\Command\Helper\OutputFormatter;
use Crustum\PluginManifest\Command\Helper\PluginDiscovery;
use Crustum\PluginManifest\Command\Helper\StarRepo;
use Crustum\PluginManifest\Manifest\BootstrapAppender;
use Crustum\PluginManifest\Manifest\ConfigMerger;
use Crustum\PluginManifest\Manifest\DependencyInstaller;
use Crustum\PluginManifest\Manifest\DependencyResolver;
use Crustum\PluginManifest\Manifest\EnvInstaller;
use Crustum\PluginManifest\Manifest\Installer;
use Crustum\PluginManifest\Manifest\ManifestRegistry;
use Crustum\PluginManifest\Manifest\Tag;

/**
 * ManifestInstall command
 *
 * Allows plugins implementing ManifestInterface to install their assets
 * to the application (config files, migrations, templates, etc.)
 */
class ManifestInstallCommand extends Command
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
            ->setDescription('Install plugin assets to the application')
            ->addOption('plugin', [
                'short' => 'p',
                'help' => 'The plugin to install assets from',
            ])
            ->addOption('tag', [
                'short' => 't',
                'help' => 'The tag to install (config, migrations, webroot, etc.)',
            ])
            ->addOption('force', [
                'short' => 'f',
                'help' => 'Overwrite existing files',
                'boolean' => true,
            ])
            ->addOption('existing', [
                'short' => 'e',
                'help' => 'Only update files that were previously installed',
                'boolean' => true,
            ])
            ->addOption('all', [
                'short' => 'a',
                'help' => 'Install all assets from all plugins',
                'boolean' => true,
            ])
            ->addOption('dry_run', [
                'short' => 'd',
                'help' => 'Preview what would be installed without making changes',
                'boolean' => true,
            ])
            ->addOption('with_dependencies', [
                'help' => 'Install plugin dependencies (prompts for optional ones)',
                'boolean' => true,
            ])
            ->addOption('all_deps', [
                'help' => 'Install all dependencies without prompting',
                'boolean' => true,
            ])
            ->addOption('no_dependencies', [
                'help' => 'Skip dependency installation',
                'boolean' => true,
            ])
            ->addOption('update_dependencies', [
                'help' => 'Update existing dependencies (re-install with --existing flag)',
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
        $manifest = new ManifestRegistry();
        $bootstrapAppender = new BootstrapAppender();
        $configMerger = new ConfigMerger();
        $envInstaller = new EnvInstaller();

        $installer = new Installer($bootstrapAppender, $configMerger, $envInstaller, $manifest);
        $dependencyResolver = new DependencyResolver();
        $dependencyInstaller = new DependencyInstaller($dependencyResolver, $installer, $manifest);
        $installer = new Installer($bootstrapAppender, $configMerger, $envInstaller, $manifest, $dependencyInstaller);

        $pluginFilter = $args->getOption('plugin');
        $tagFilter = $args->getOption('tag');
        $all = $args->getOption('all');
        $force = $args->getOption('force');
        $existing = $args->getOption('existing');
        $dryRun = $args->getOption('dry_run');
        $withDependencies = $args->getOption('with_dependencies');
        $allDeps = $args->getOption('all_deps');
        $noDependencies = $args->getOption('no_dependencies');
        $updateDependencies = $args->getOption('update_dependencies');

        if ($dryRun) {
            $io->info('<info>Dry run mode: No changes will be made</info>');
            $io->out('');
        }

        $pluginDiscovery = new PluginDiscovery();
        $plugins = $pluginDiscovery->discoverPublishablePlugins($io);

        if (empty($plugins)) {
            $io->warning('No plugins with publishable assets found.');

            return static::CODE_SUCCESS;
        }

        if (!$pluginFilter && !$all) {
            [$pluginFilter, $tagFilter] = $this->promptForSelection($io, $plugins);

            if (!$pluginFilter) {
                $io->info('Installation cancelled.');

                return static::CODE_SUCCESS;
            }
        }

        $options = [
            'force' => $force,
            'existing' => $existing || $updateDependencies,
            'dry_run' => $dryRun,
            'with_dependencies' => $withDependencies || $updateDependencies,
            'all_deps' => $allDeps,
            'no_dependencies' => $noDependencies,
            'update_dependencies' => $updateDependencies,
            'console_io' => $io,
        ];

        $installation = $this->createInstallationHelper($installer, $manifest);

        if ($all) {
            return $installation->installAllPlugins($io, $plugins, $options);
        }

        if ($pluginFilter && !isset($plugins[$pluginFilter])) {
            $io->error("Plugin '{$pluginFilter}' not found or does not implement ManifestInterface.");

            return static::CODE_ERROR;
        }

        $pluginName = is_string($pluginFilter) ? $pluginFilter : '';
        $tag = is_string($tagFilter) ? $tagFilter : null;

        return $installation->installPlugin($io, $pluginName, $plugins[$pluginName], $tag, $options);
    }

    /**
     * Create installation helper with dependencies
     *
     * @param \Crustum\PluginManifest\Manifest\Installer $installer Installer instance
     * @param \Crustum\PluginManifest\Manifest\ManifestRegistry $registry Registry
     * @return \Crustum\PluginManifest\Command\Helper\Installation Installation helper
     */
    protected function createInstallationHelper(Installer $installer, ManifestRegistry $registry): Installation
    {
        $outputFormatter = new OutputFormatter();
        $starRepo = new StarRepo($registry);

        return new Installation($installer, $registry, $outputFormatter, $starRepo);
    }

    /**
     * Prompt user to select what to install (two-level menu)
     *
     * First level: Select plugin
     * Second level: Select tag/assets for that plugin
     *
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @param array<string, array<string, mixed>> $plugins Available plugins
     * @return array{string|null, int|string|null} [plugin name, tag name]
     */
    protected function promptForSelection(ConsoleIo $io, array $plugins): array
    {
        $selectedPluginName = $this->promptForPlugin($io, $plugins);

        if ($selectedPluginName === null) {
            return [null, null];
        }

        $selectedTag = $this->promptForTag($io, $selectedPluginName, $plugins[$selectedPluginName]);

        return [$selectedPluginName, $selectedTag];
    }

    /**
     * Prompt user to select a plugin
     *
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @param array<string, array<string, mixed>> $plugins Available plugins
     * @return string|null Selected plugin name or null if cancelled
     */
    protected function promptForPlugin(ConsoleIo $io, array $plugins): ?string
    {
        $pluginNames = array_keys($plugins);

        $io->out('<info>Select a plugin:</info>');
        $io->out('');

        $pluginChoices = ['Cancel'];
        foreach ($pluginNames as $pluginName) {
            $tags = array_keys($plugins[$pluginName]['assets']);
            $totalAssets = 0;
            foreach ($tags as $tag) {
                $totalAssets += count($plugins[$pluginName]['assets'][$tag]);
            }
            $pluginChoices[] = "{$pluginName} ({$totalAssets} asset(s) in " . count($tags) . ' tag(s))';
        }

        foreach ($pluginChoices as $idx => $choice) {
            $io->out(sprintf('  [%d] %s', $idx, $choice));
        }
        $io->out('');

        $maxIndex = count($pluginChoices) - 1;
        $input = trim($io->ask('Enter your choice', '0'));

        $selectedPluginIndex = null;
        if (is_numeric($input)) {
            $num = (int)$input;
            if ($num >= 0 && $num <= $maxIndex) {
                $selectedPluginIndex = $num;
            }
        }

        if ($selectedPluginIndex === null || $selectedPluginIndex === 0) {
            return null;
        }

        return $pluginNames[$selectedPluginIndex - 1];
    }

    /**
     * Prompt user to select a tag for the selected plugin
     *
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @param string $pluginName Selected plugin name
     * @param array<string, mixed> $pluginData Plugin data
     * @return string|null Selected tag name or null if cancelled/back
     */
    protected function promptForTag(ConsoleIo $io, string $pluginName, array $pluginData): ?string
    {
        $io->out('');
        $io->out("<info>Select assets from <warning>{$pluginName}</warning>:</info>");
        $io->out('');

        $tagChoices = ['All assets', 'Back'];
        $tagMap = [null, null];

        $tags = array_keys($pluginData['assets']);
        foreach ($tags as $tag) {
            if ($tag === Tag::STAR_REPO) {
                continue;
            }

            $assetCount = count($pluginData['assets'][$tag]);
            $tagChoices[] = "{$tag} ({$assetCount} asset(s))";
            $tagMap[] = $tag;
        }

        foreach ($tagChoices as $idx => $choice) {
            $io->out(sprintf('  [%d] %s', $idx, $choice));
        }
        $io->out('');

        $maxTagIndex = count($tagChoices) - 1;
        $tagInput = trim($io->ask('Enter your choice', '0'));

        $selectedTagIndex = null;
        if (is_numeric($tagInput)) {
            $num = (int)$tagInput;
            if ($num >= 0 && $num <= $maxTagIndex) {
                $selectedTagIndex = $num;
            }
        }

        if ($selectedTagIndex === null || $selectedTagIndex === 1) {
            return null;
        }

        $selectedTag = $tagMap[$selectedTagIndex] ?? null;

        return is_string($selectedTag) ? $selectedTag : null;
    }
}
