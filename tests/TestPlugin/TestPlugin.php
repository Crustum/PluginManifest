<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Test\TestPlugin;

use Cake\Core\BasePlugin;
use Crustum\PluginManifest\Manifest\ManifestInterface;
use Crustum\PluginManifest\Manifest\ManifestTrait;

class TestPlugin extends BasePlugin implements ManifestInterface
{
    use ManifestTrait;

    public static function manifest(): array
    {
        $pluginPath = dirname(__DIR__) . DS . 'TestPlugin' . DS;

        return array_merge(
            static::manifestConfig(
                $pluginPath . 'config' . DS . 'test_config.php',
                CONFIG . 'test_config.php',
                false,
            ),
            static::manifestMigrations(
                $pluginPath . 'config' . DS . 'Migrations',
                CONFIG . 'Migrations',
            ),
            static::manifestBootstrapAppend(
                "Plugin::load('TestPlugin');",
                '// TestPlugin bootstrap marker',
            ),
            static::manifestEnvVars(
                [
                    'TEST_PLUGIN_VAR' => 'test_value',
                    'TEST_PLUGIN_DEBUG' => 'true',
                ],
                '# TestPlugin environment variables',
            ),
            static::manifestConfigMerge(
                'TestPlugin',
                [
                    'enabled' => true,
                    'timeout' => 30,
                ],
            ),
        );
    }
}
