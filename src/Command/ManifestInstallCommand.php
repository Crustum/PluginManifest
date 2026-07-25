<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Crustum\PluginManifest\Command\Helper\Installation;
use Crustum\PluginManifest\Command\Helper\InstallSelection;
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
use Override;

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
     * @inheritDoc
     */
    #[Override]
    public static function getDescription(): string
    {
        return 'Install plugin assets to the application';
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

        $outputFormatter = new OutputFormatter();
        if ($dryRun) {
            $outputFormatter->displayDryRunNote($io);
        }

        $pluginDiscovery = new PluginDiscovery();
        $plugins = $pluginDiscovery->discoverPublishablePlugins($io);

        if ($plugins === []) {
            $io->warning('No plugins with publishable assets found.');

            return static::CODE_SUCCESS;
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

        if (!$pluginFilter) {
            $selections = $this->createInstallSelection()->prompt($io, $plugins);

            if ($selections === null || $selections === []) {
                $io->info('Installation cancelled.');

                return static::CODE_SUCCESS;
            }

            return $installation->installSelections($io, $plugins, $selections, $options);
        }

        if (!isset($plugins[$pluginFilter])) {
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
     * Create interactive selection helper.
     *
     * @return \Crustum\PluginManifest\Command\Helper\InstallSelection Selection helper
     */
    protected function createInstallSelection(): InstallSelection
    {
        return new InstallSelection();
    }
}
