<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Test\TestCase\Manifest;

use Cake\TestSuite\TestCase;
use Crustum\PluginManifest\Manifest\ManifestRegistry;

class ManifestRegistryTest extends TestCase
{
    protected string $testRegistryFile;
    protected ManifestRegistry $registry;

    public function setUp(): void
    {
        parent::setUp();

        $this->testRegistryFile = TMP . 'test_manifest_registry_' . uniqid() . '.php';

        defined('CONFIG') || define('CONFIG', TMP);

        $this->registry = new class ($this->testRegistryFile) extends ManifestRegistry {
            protected string $manifestFile;

            public function __construct(string $manifestFile)
            {
                $this->manifestFile = $manifestFile;
            }

            protected function load(): array
            {
                if (!file_exists($this->manifestFile)) {
                    return [];
                }

                return require $this->manifestFile;
            }

            protected function save(array $manifest): void
            {
                $directory = dirname($this->manifestFile);
                if (!is_dir($directory)) {
                    mkdir($directory, 0755, true);
                }

                $content = "<?php\n\nreturn " . var_export($manifest, true) . ";\n";
                file_put_contents($this->manifestFile, $content);
            }
        };
    }

    public function tearDown(): void
    {
        parent::tearDown();

        if (file_exists($this->testRegistryFile)) {
            unlink($this->testRegistryFile);
        }

        $manifestRegistryFile = CONFIG . 'manifest_registry.php';
        if (file_exists($manifestRegistryFile)) {
            unlink($manifestRegistryFile);
        }
    }

    public function testRecordInstalled(): void
    {
        $this->registry->recordInstalled('TestPlugin', 'copy', 'config', [
            'destination' => '/path/to/file.php',
            'source' => '/source/file.php',
        ]);

        $installed = $this->registry->getInstalled('TestPlugin', 'copy', 'config');

        $this->assertCount(1, $installed);
        $this->assertEquals('/path/to/file.php', $installed[0]['destination']);
        $this->assertEquals('/source/file.php', $installed[0]['source']);
        $this->assertArrayHasKey('installed_at', $installed[0]);
    }

    public function testRecordMultipleInstallations(): void
    {
        $this->registry->recordInstalled('TestPlugin', 'copy', 'config', [
            'destination' => '/path/file1.php',
        ]);

        $this->registry->recordInstalled('TestPlugin', 'copy', 'migrations', [
            'destination' => '/path/migration1.php',
        ]);

        $this->registry->recordInstalled('AnotherPlugin', 'append', 'bootstrap', [
            'destination' => '/path/bootstrap.php',
        ]);

        $allInstalled = $this->registry->getInstalled();

        $this->assertArrayHasKey('TestPlugin', $allInstalled);
        $this->assertArrayHasKey('AnotherPlugin', $allInstalled);

        $testPluginConfig = $this->registry->getInstalled('TestPlugin', 'copy', 'config');
        $this->assertCount(1, $testPluginConfig);

        $testPluginMigrations = $this->registry->getInstalled('TestPlugin', 'copy', 'migrations');
        $this->assertCount(1, $testPluginMigrations);
    }

    public function testIsAppendCompleted(): void
    {
        $asset = [
            'destination' => '/path/bootstrap.php',
            'marker' => '// Test marker',
        ];

        $this->assertFalse($this->registry->isOperationCompleted('TestPlugin', 'append', 'bootstrap', $asset));

        $this->registry->recordInstalled('TestPlugin', 'append', 'bootstrap', [
            'destination' => '/path/bootstrap.php',
            'marker' => '// Test marker',
            'completed' => true,
        ]);

        $this->assertTrue($this->registry->isOperationCompleted('TestPlugin', 'append', 'bootstrap', $asset));
    }

    public function testIsMergeCompleted(): void
    {
        $asset = [
            'destination' => '/path/app.php',
            'key' => 'TestPlugin',
        ];

        $this->assertFalse($this->registry->isOperationCompleted('TestPlugin', 'merge', 'config', $asset));

        $this->registry->recordInstalled('TestPlugin', 'merge', 'config', [
            'destination' => '/path/app.php',
            'key' => 'TestPlugin',
            'completed' => true,
        ]);

        $this->assertTrue($this->registry->isOperationCompleted('TestPlugin', 'merge', 'config', $asset));
    }

    public function testAppendEnvAlwaysReturnsFalse(): void
    {
        $asset = [
            'destination' => '/path/.env',
            'env_vars' => ['TEST_VAR' => 'value'],
        ];

        $this->assertFalse($this->registry->isOperationCompleted('TestPlugin', 'append-env', 'envs', $asset));

        $this->registry->recordInstalled('TestPlugin', 'append-env', 'envs', [
            'destination' => '/path/.env',
            'env_vars' => ['TEST_VAR'],
            'added_count' => 1,
        ]);

        $this->assertFalse($this->registry->isOperationCompleted('TestPlugin', 'append-env', 'envs', $asset));
    }

    public function testCanReinstall(): void
    {
        $this->assertTrue($this->registry->canReinstall('copy'));
        $this->assertTrue($this->registry->canReinstall('append-env'));
        $this->assertFalse($this->registry->canReinstall('append'));
        $this->assertFalse($this->registry->canReinstall('merge'));
        $this->assertFalse($this->registry->canReinstall('copy-safe'));
    }

    public function testGetInstalledWithFilters(): void
    {
        $this->registry->recordInstalled('Plugin1', 'copy', 'config', ['file' => 'config1.php']);
        $this->registry->recordInstalled('Plugin1', 'copy', 'migrations', ['file' => 'migration1.php']);
        $this->registry->recordInstalled('Plugin1', 'append', 'bootstrap', ['file' => 'bootstrap.php']);
        $this->registry->recordInstalled('Plugin2', 'copy', 'config', ['file' => 'config2.php']);

        $plugin1All = $this->registry->getInstalled('Plugin1');
        $this->assertArrayHasKey('copy', $plugin1All);
        $this->assertArrayHasKey('append', $plugin1All);

        $plugin1Copy = $this->registry->getInstalled('Plugin1', 'copy');
        $this->assertArrayHasKey('config', $plugin1Copy);
        $this->assertArrayHasKey('migrations', $plugin1Copy);

        $plugin1CopyConfig = $this->registry->getInstalled('Plugin1', 'copy', 'config');
        $this->assertCount(1, $plugin1CopyConfig);
        $this->assertEquals('config1.php', $plugin1CopyConfig[0]['file']);
    }

    public function testPersistence(): void
    {
        $this->registry->recordInstalled('TestPlugin', 'copy', 'config', [
            'destination' => '/path/file.php',
        ]);

        $newRegistry = new class ($this->testRegistryFile) extends ManifestRegistry {
            protected string $manifestFile;

            public function __construct(string $manifestFile)
            {
                $this->manifestFile = $manifestFile;
            }

            protected function load(): array
            {
                if (!file_exists($this->manifestFile)) {
                    return [];
                }

                return require $this->manifestFile;
            }

            protected function save(array $manifest): void
            {
                $content = "<?php\n\nreturn " . var_export($manifest, true) . ";\n";
                file_put_contents($this->manifestFile, $content);
            }
        };

        $installed = $newRegistry->getInstalled('TestPlugin', 'copy', 'config');

        $this->assertCount(1, $installed);
        $this->assertEquals('/path/file.php', $installed[0]['destination']);
    }
}
