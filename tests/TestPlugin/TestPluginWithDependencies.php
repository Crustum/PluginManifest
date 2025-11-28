<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Test\TestPlugin;

use Cake\Core\BasePlugin;
use Crustum\PluginManifest\Manifest\ManifestInterface;
use Crustum\PluginManifest\Manifest\ManifestTrait;

/**
 * Test plugin that demonstrates dependency functionality
 */
class TestPluginWithDependencies extends BasePlugin implements ManifestInterface
{
    use ManifestTrait;

    public static function manifest(): array
    {
        $pluginPath = dirname(__DIR__) . DS . 'TestPlugin' . DS;

        return array_merge(
            // Regular assets
            static::manifestConfig(
                $pluginPath . 'config' . DS . 'test_config.php',
                CONFIG . 'test_with_deps.php',
                false,
            ),
            // Dependencies
            static::manifestDependencies([
                'TestDependencyA' => [
                    'required' => true,
                    'tags' => ['config'],
                    'reason' => 'Required for basic functionality',
                ],
                'TestDependencyB' => [
                    'required' => false,
                    'tags' => ['config'],
                    'prompt' => 'Install TestDependencyB plugin for additional features?',
                    'reason' => 'Provides additional test capabilities',
                ],
                'TestDependencyC' => [
                    'required' => false,
                    'tags' => ['config', 'migrations'],
                    'condition' => 'file_exists',
                    'condition_path' => CONFIG . 'test_condition.php',
                    'prompt' => 'Test condition detected. Install TestDependencyC plugin?',
                    'reason' => 'Enhanced functionality when test condition is met',
                ],
            ]),
        );
    }
}
