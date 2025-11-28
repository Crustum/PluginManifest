<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Test\TestCase\Command;

use Cake\TestSuite\TestCase;
use Crustum\PluginManifest\Test\TestPlugin\TestPlugin;

class ManifestInstallCommandTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
    }

    public function tearDown(): void
    {
        parent::tearDown();
    }

    public function testTestPluginManifestStructure(): void
    {
        $pluginClass = TestPlugin::class;
        $manifest = $pluginClass::manifest();

        $this->assertIsArray($manifest);
        $this->assertNotEmpty($manifest);

        $types = array_column($manifest, 'type');
        $this->assertContains('copy', $types);
        $this->assertContains('append', $types);
        $this->assertContains('append-env', $types);
        $this->assertContains('merge', $types);

        $tags = array_column($manifest, 'tag');
        $this->assertContains('config', $tags);
        $this->assertContains('migrations', $tags);
        $this->assertContains('bootstrap', $tags);
        $this->assertContains('envs', $tags);
    }

    public function testManifestConfigAssetStructure(): void
    {
        $pluginClass = TestPlugin::class;
        $manifest = $pluginClass::manifest();

        $configAssets = array_filter($manifest, function ($asset) {
            return $asset['tag'] === 'config' && isset($asset['source']);
        });

        $this->assertNotEmpty($configAssets);

        $configAsset = array_values($configAssets)[0];
        $this->assertArrayHasKey('source', $configAsset);
        $this->assertArrayHasKey('destination', $configAsset);
        $this->assertArrayHasKey('type', $configAsset);
        $this->assertArrayHasKey('tag', $configAsset);
        $this->assertStringContainsString('test_config.php', $configAsset['source']);
        $this->assertEquals('copy-safe', $configAsset['type']);
    }

    public function testManifestMigrationAssetStructure(): void
    {
        $pluginClass = TestPlugin::class;
        $manifest = $pluginClass::manifest();

        $migrationAssets = array_filter($manifest, function ($asset) {
            return $asset['tag'] === 'migrations';
        });

        $this->assertNotEmpty($migrationAssets);

        $migrationAsset = array_values($migrationAssets)[0];
        $this->assertArrayHasKey('source', $migrationAsset);
        $this->assertArrayHasKey('destination', $migrationAsset);
        $this->assertArrayHasKey('options', $migrationAsset);
        $this->assertArrayHasKey('rename_with_plugin', $migrationAsset['options']);
        $this->assertArrayHasKey('plugin_namespace', $migrationAsset['options']);
        $this->assertEquals('Test', $migrationAsset['options']['plugin_namespace']);
    }

    public function testManifestBootstrapAssetStructure(): void
    {
        $pluginClass = TestPlugin::class;
        $manifest = $pluginClass::manifest();

        $bootstrapAssets = array_filter($manifest, function ($asset) {
            return $asset['type'] === 'append' && $asset['tag'] === 'bootstrap';
        });

        $this->assertNotEmpty($bootstrapAssets);

        $bootstrapAsset = array_values($bootstrapAssets)[0];
        $this->assertArrayHasKey('destination', $bootstrapAsset);
        $this->assertArrayHasKey('content', $bootstrapAsset);
        $this->assertArrayHasKey('marker', $bootstrapAsset);
        $this->assertStringContainsString('TestPlugin', $bootstrapAsset['content']);
        $this->assertStringContainsString('TestPlugin bootstrap marker', $bootstrapAsset['marker']);
    }

    public function testManifestEnvAssetStructure(): void
    {
        $pluginClass = TestPlugin::class;
        $manifest = $pluginClass::manifest();

        $envAssets = array_filter($manifest, function ($asset) {
            return $asset['type'] === 'append-env';
        });

        $this->assertNotEmpty($envAssets);

        $envAsset = array_values($envAssets)[0];
        $this->assertArrayHasKey('destination', $envAsset);
        $this->assertArrayHasKey('env_vars', $envAsset);
        $this->assertArrayHasKey('comment', $envAsset);
        $this->assertIsArray($envAsset['env_vars']);
        $this->assertNotEmpty($envAsset['env_vars']);
    }

    public function testManifestMergeAssetStructure(): void
    {
        $pluginClass = TestPlugin::class;
        $manifest = $pluginClass::manifest();

        $mergeAssets = array_filter($manifest, function ($asset) {
            return $asset['type'] === 'merge';
        });

        $this->assertNotEmpty($mergeAssets);

        $mergeAsset = array_values($mergeAssets)[0];
        $this->assertArrayHasKey('destination', $mergeAsset);
        $this->assertArrayHasKey('key', $mergeAsset);
        $this->assertArrayHasKey('value', $mergeAsset);
        $this->assertEquals('TestPlugin', $mergeAsset['key']);
        $this->assertIsArray($mergeAsset['value']);
    }
}
