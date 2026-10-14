<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Test\TestPlugin\Manifest;

use Crustum\PluginManifest\Manifest\ManifestOps;
use Crustum\PluginManifest\Test\TestPlugin\PlainPlugin;

class PlainManifest
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function manifest(): array
    {
        $plugin = ManifestOps::pluginName(PlainPlugin::class);

        return ManifestOps::manifestConfig(
            $plugin,
            '/tmp/plain.php',
            '/tmp/plain.php',
        );
    }
}
