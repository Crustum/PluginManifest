<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Command;

use Cake\Command\Command;
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
use Crustum\PluginManifest\Manifest\PluginLoader;
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
                'help' => 'Install optional dependencies too, prompting for each (dependencies install by default)',
                'boolean' => true,
            ])
            ->addOption('all_deps', [
                'help' => 'Install all dependencies without prompting',
                'boolean' => true,
            ])
            ->addOption('no_dependencies', [
                'help' => 'Skip dependency installation (required dependencies install by default)',
                'boolean' => true,
            ])
            ->addOption('update_dependencies', [
                'help' => 'Update existing dependencies (re-install with --existing flag)',
                'boolean' => true,
            ])
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
        return 'Install plugin assets to the application';
    }

    /**
     * Implement this method with your command's logic
     *
     * @return int|null The exit code or null for success
     */
    public function execute(): ?int
    {
        $manifest = new ManifestRegistry();
        $bootstrapAppender = new BootstrapAppender();
        $configMerger = new ConfigMerger();
        $envInstaller = new EnvInstaller();
        $installer = new Installer($bootstrapAppender, $configMerger, $envInstaller, $manifest);
        $dependencyResolver = new DependencyResolver();
        $dependencyInstaller = new DependencyInstaller($dependencyResolver, $installer, $manifest);
        $installer = new Installer($bootstrapAppender, $configMerger, $envInstaller, $manifest, $dependencyInstaller);
        $pluginFilter = $this->args->getOption('plugin');
        $tagFilter = $this->args->getOption('tag');
        $all = $this->args->getOption('all');
        $force = $this->args->getOption('force');
        $existing = $this->args->getOption('existing');
        $dryRun = $this->args->getOption('dry_run');
        $withDependencies = $this->args->getOption('with_dependencies');
        $allDeps = $this->args->getOption('all_deps');
        $noDependencies = $this->args->getOption('no_dependencies');
        $updateDependencies = $this->args->getOption('update_dependencies');
        $outputFormatter = new OutputFormatter();
        if ($dryRun) {
            $outputFormatter->displayDryRunNote($this->io);
        }

        $pluginDiscovery = new PluginDiscovery();
        $plugins = $pluginDiscovery->discoverPublishablePlugins(
            $this->io,
            PluginDiscovery::normalizePaths($this->args->getOption('path')),
        );

        if ($plugins === []) {
            $this->io->warning('No plugins with publishable assets found.');

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
            'console_io' => $this->io,
            'plugin_loader' => new PluginLoader(),
        ];
        $installation = $this->createInstallationHelper($installer, $manifest);
        if ($all) {
            return $installation->installAllPlugins($this->io, $plugins, $options);
        }

        if (!$pluginFilter) {
            $selections = $this->createInstallSelection()->prompt($this->io, $plugins);

            if ($selections === null || $selections === []) {
                $this->io->info('Installation cancelled.');

                return static::CODE_SUCCESS;
            }

            return $installation->installSelections($this->io, $plugins, $selections, $options);
        }

        if (!isset($plugins[$pluginFilter])) {
            $this->io->error("Plugin '{$pluginFilter}' not found or does not provide a manifest.");

            return static::CODE_ERROR;
        }

        $pluginName = is_string($pluginFilter) ? $pluginFilter : '';
        $tag = is_string($tagFilter) ? $tagFilter : null;

        return $installation->installPlugin($this->io, $pluginName, $plugins[$pluginName], $tag, $options);
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
