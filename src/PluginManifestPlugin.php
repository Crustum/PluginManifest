<?php
declare(strict_types=1);

namespace Crustum\PluginManifest;

use Cake\Console\CommandCollection;
use Cake\Core\BasePlugin;
use Crustum\PluginManifest\Command\ManifestDependenciesCommand;
use Crustum\PluginManifest\Command\ManifestInstallCommand;
use Crustum\PluginManifest\Command\ManifestListCommand;
use Crustum\PluginManifest\Command\ManifestStatusCommand;

/**
 * Plugin for PluginManifest
 */
class PluginManifestPlugin extends BasePlugin
{
    /**
     * Add commands for the plugin.
     *
     * @param \Cake\Console\CommandCollection $commands The command collection to update.
     * @return \Cake\Console\CommandCollection
     */
    public function console(CommandCollection $commands): CommandCollection
    {
        $commands = parent::console($commands);

        $commands->add('manifest install', ManifestInstallCommand::class);
        $commands->add('manifest list', ManifestListCommand::class);
        $commands->add('manifest dependencies', ManifestDependenciesCommand::class);
        $commands->add('manifest status', ManifestStatusCommand::class);

        return $commands;
    }
}
