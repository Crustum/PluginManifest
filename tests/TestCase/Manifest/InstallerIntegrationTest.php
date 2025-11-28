<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Test\TestCase\Manifest;

use Cake\TestSuite\TestCase;
use Crustum\PluginManifest\Manifest\BootstrapAppender;
use Crustum\PluginManifest\Manifest\ConfigMerger;
use Crustum\PluginManifest\Manifest\EnvInstaller;
use Crustum\PluginManifest\Manifest\Installer;
use Crustum\PluginManifest\Manifest\ManifestRegistry;

class InstallerIntegrationTest extends TestCase
{
    protected string $testDir;
    protected Installer $installer;

    public function setUp(): void
    {
        parent::setUp();

        $this->testDir = TMP . 'tests' . DS . 'installer_' . uniqid() . DS;
        mkdir($this->testDir, 0777, true);

        $manifest = new ManifestRegistry();
        $bootstrapAppender = new BootstrapAppender();
        $configMerger = new ConfigMerger();
        $envInstaller = new EnvInstaller();
        $this->installer = new Installer($bootstrapAppender, $configMerger, $envInstaller, $manifest);
    }

    public function tearDown(): void
    {
        parent::tearDown();

        $this->recursiveDelete($this->testDir);
    }

    protected function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DS . $file;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function testCopyOperation(): void
    {
        $sourceFile = $this->testDir . 'source.txt';
        $destFile = $this->testDir . 'dest.txt';

        file_put_contents($sourceFile, 'test content');

        $asset = [
            'type' => 'copy',
            'source' => $sourceFile,
            'destination' => $destFile,
            'tag' => 'test',
        ];

        $result = $this->installer->install($asset);

        $this->assertTrue($result->success);
        $this->assertEquals('installed', $result->status);
        $this->assertFileExists($destFile);
        $this->assertEquals('test content', file_get_contents($destFile));
    }

    public function testCopyOperationSkipsExistingFile(): void
    {
        $sourceFile = $this->testDir . 'source.txt';
        $destFile = $this->testDir . 'dest.txt';

        file_put_contents($sourceFile, 'new content');
        file_put_contents($destFile, 'existing content');

        $asset = [
            'type' => 'copy',
            'source' => $sourceFile,
            'destination' => $destFile,
            'tag' => 'test',
        ];

        $result = $this->installer->install($asset);

        $this->assertFalse($result->success);
        $this->assertEquals('skipped', $result->status);
        $this->assertEquals('existing content', file_get_contents($destFile));
    }

    public function testCopyOperationWithForce(): void
    {
        $sourceFile = $this->testDir . 'source.txt';
        $destFile = $this->testDir . 'dest.txt';

        file_put_contents($sourceFile, 'new content');
        file_put_contents($destFile, 'existing content');

        $asset = [
            'type' => 'copy',
            'source' => $sourceFile,
            'destination' => $destFile,
            'tag' => 'test',
        ];

        $result = $this->installer->install($asset, ['force' => true]);

        $this->assertTrue($result->success);
        $this->assertEquals('installed', $result->status);
        $this->assertEquals('new content', file_get_contents($destFile));
    }

    public function testCopySafeNeverOverwrites(): void
    {
        $sourceFile = $this->testDir . 'source.txt';
        $destFile = $this->testDir . 'dest.txt';

        file_put_contents($sourceFile, 'new content');
        file_put_contents($destFile, 'existing content');

        $asset = [
            'type' => 'copy-safe',
            'source' => $sourceFile,
            'destination' => $destFile,
            'tag' => 'test',
        ];

        $result = $this->installer->install($asset, ['force' => true]);

        $this->assertFalse($result->success);
        $this->assertEquals('skipped', $result->status);
        $this->assertEquals('existing content', file_get_contents($destFile));
    }

    public function testCopyDirectory(): void
    {
        $sourceDir = $this->testDir . 'source' . DS;
        $destDir = $this->testDir . 'dest' . DS;

        mkdir($sourceDir, 0777, true);
        mkdir($sourceDir . 'subdir', 0777, true);

        file_put_contents($sourceDir . 'file1.txt', 'content1');
        file_put_contents($sourceDir . 'file2.txt', 'content2');
        file_put_contents($sourceDir . 'subdir' . DS . 'file3.txt', 'content3');

        $asset = [
            'type' => 'copy',
            'source' => $sourceDir,
            'destination' => $destDir,
            'tag' => 'test',
        ];

        $result = $this->installer->install($asset);

        $this->assertTrue($result->success);
        $this->assertFileExists($destDir . 'file1.txt');
        $this->assertFileExists($destDir . 'file2.txt');
        $this->assertFileExists($destDir . 'subdir' . DS . 'file3.txt');
        $this->assertEquals('content1', file_get_contents($destDir . 'file1.txt'));
        $this->assertEquals('content3', file_get_contents($destDir . 'subdir' . DS . 'file3.txt'));
    }

    public function testAppendOperation(): void
    {
        $targetFile = $this->testDir . 'bootstrap.php';
        file_put_contents($targetFile, "<?php\n\n// Existing content\n");

        $asset = [
            'type' => 'append',
            'destination' => $targetFile,
            'content' => "Plugin::load('TestPlugin');",
            'marker' => '// TestPlugin marker',
            'tag' => 'bootstrap',
            'plugin' => 'TestPlugin',
        ];

        $result = $this->installer->install($asset);

        $this->assertTrue($result->success);
        $this->assertEquals('appended', $result->status);

        $content = file_get_contents($targetFile);
        $this->assertStringContainsString('// TestPlugin marker', $content);
        $this->assertStringContainsString("Plugin::load('TestPlugin');", $content);
    }

    public function testAppendOperationSkipsDuplicate(): void
    {
        $targetFile = $this->testDir . 'bootstrap.php';
        file_put_contents($targetFile, "<?php\n\n// TestPlugin marker\nPlugin::load('TestPlugin');\n");

        $asset = [
            'type' => 'append',
            'destination' => $targetFile,
            'content' => "Plugin::load('TestPlugin');",
            'marker' => '// TestPlugin marker',
            'tag' => 'bootstrap',
            'plugin' => 'TestPlugin',
        ];

        $result = $this->installer->install($asset);

        $this->assertFalse($result->success);
        $this->assertEquals('skipped', $result->status);
        $this->assertStringContainsString('Marker already exists', $result->message);
    }

    public function testAppendEnvOperation(): void
    {
        $envFile = $this->testDir . '.env';
        file_put_contents($envFile, "EXISTING_VAR=value\n");

        $asset = [
            'type' => 'append-env',
            'destination' => $envFile,
            'env_vars' => [
                'NEW_VAR' => 'new_value',
                'ANOTHER_VAR' => 'another_value',
            ],
            'comment' => '# TestPlugin variables',
            'tag' => 'envs',
            'plugin' => 'TestPlugin',
        ];

        $result = $this->installer->install($asset);

        $this->assertTrue($result->success);
        $content = file_get_contents($envFile);

        $this->assertStringContainsString('# TestPlugin variables', $content);
        $this->assertStringContainsString('NEW_VAR=new_value', $content);
        $this->assertStringContainsString('ANOTHER_VAR=another_value', $content);
        $this->assertStringContainsString('EXISTING_VAR=value', $content);
    }

    public function testMergeOperation(): void
    {
        $configFile = $this->testDir . 'app.php';
        file_put_contents($configFile, "<?php\n\nreturn [\n    'existing' => 'value',\n];\n");

        $asset = [
            'type' => 'merge',
            'destination' => $configFile,
            'key' => 'TestPlugin',
            'value' => ['enabled' => true, 'timeout' => 30],
            'tag' => 'config',
            'plugin' => 'TestPlugin',
        ];

        $result = $this->installer->install($asset);

        $this->assertTrue($result->success);
        $this->assertEquals('merged', $result->status);

        $config = require $configFile;
        $this->assertArrayHasKey('TestPlugin', $config);
        $this->assertEquals(['enabled' => true, 'timeout' => 30], $config['TestPlugin']);
        $this->assertEquals('value', $config['existing']);
    }

    public function testMergeOperationSkipsExistingKey(): void
    {
        $configFile = $this->testDir . 'app.php';
        file_put_contents($configFile, "<?php\n\nreturn [\n    'TestPlugin' => ['old' => 'value'],\n];\n");

        $asset = [
            'type' => 'merge',
            'destination' => $configFile,
            'key' => 'TestPlugin',
            'value' => ['enabled' => true],
            'tag' => 'config',
            'plugin' => 'TestPlugin',
        ];

        $result = $this->installer->install($asset);

        $this->assertFalse($result->success);
        $this->assertEquals('skipped', $result->status);
    }

    public function testMigrationInstallation(): void
    {
        $sourceDir = $this->testDir . 'source_migrations' . DS;
        $destDir = $this->testDir . 'dest_migrations' . DS;

        mkdir($sourceDir, 0777, true);
        mkdir($destDir, 0777, true);

        $migrationContent = "<?php\n\nclass CreateTestTable extends AbstractMigration\n{\n}\n";
        file_put_contents($sourceDir . '20250101000000_CreateTestTable.php', $migrationContent);

        $asset = [
            'type' => 'copy',
            'source' => $sourceDir,
            'destination' => $destDir,
            'tag' => 'migrations',
            'options' => [
                'rename_with_plugin' => true,
                'plugin_namespace' => 'TestPlugin',
            ],
        ];

        $result = $this->installer->install($asset);

        $this->assertTrue($result->success);

        $installedFile = $destDir . '20250101000000_TestPluginCreateTestTable.php';
        $this->assertFileExists($installedFile);

        $content = file_get_contents($installedFile);
        $this->assertStringContainsString('class TestPluginCreateTestTable extends AbstractMigration', $content);
    }

    public function testDryRunMode(): void
    {
        $sourceFile = $this->testDir . 'source.txt';
        $destFile = $this->testDir . 'dest.txt';

        file_put_contents($sourceFile, 'test content');

        $asset = [
            'type' => 'copy',
            'source' => $sourceFile,
            'destination' => $destFile,
            'tag' => 'test',
        ];

        $this->installer->install($asset, ['dry_run' => true]);

        $this->assertFileDoesNotExist($destFile);
    }
}
