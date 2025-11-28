<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Test\TestCase\Manifest;

use Cake\TestSuite\TestCase;
use Crustum\PluginManifest\Manifest\BootstrapAppender;

class BootstrapAppenderTest extends TestCase
{
    protected string $testDir;
    protected BootstrapAppender $appender;

    public function setUp(): void
    {
        parent::setUp();

        $this->testDir = TMP . 'tests' . DS . 'bootstrap_' . uniqid() . DS;
        mkdir($this->testDir, 0777, true);

        $this->appender = new BootstrapAppender();
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

        $files = scandir($dir);
        if ($files === false) {
            return;
        }

        $files = array_diff($files, ['.', '..']);
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

    public function testAppendToBootstrapFile(): void
    {
        $file = $this->testDir . 'bootstrap.php';
        file_put_contents($file, "<?php\n\n// Existing content\n");

        $result = $this->appender->append($file, "Plugin::load('Test');", '// Test marker');

        $this->assertTrue($result->success);
        $this->assertEquals('appended', $result->status);

        $content = file_get_contents($file);
        $this->assertStringContainsString('// Test marker', $content);
        $this->assertStringContainsString("Plugin::load('Test');", $content);
    }

    public function testSkipWhenMarkerExists(): void
    {
        $file = $this->testDir . 'bootstrap.php';
        file_put_contents($file, "<?php\n\n// Test marker\nPlugin::load('Test');\n");

        $result = $this->appender->append($file, "Plugin::load('Test');", '// Test marker');

        $this->assertFalse($result->success);
        $this->assertEquals('skipped', $result->status);
        $this->assertStringContainsString('Marker already exists', $result->message);
    }

    public function testSkipWhenContentExists(): void
    {
        $file = $this->testDir . 'bootstrap.php';
        file_put_contents($file, "<?php\n\nPlugin::load('Test');\n");

        $result = $this->appender->append($file, "Plugin::load('Test');");

        $this->assertFalse($result->success);
        $this->assertEquals('skipped', $result->status);
        $this->assertStringContainsString('Content already exists', $result->message);
    }

    public function testErrorWhenFileDoesNotExist(): void
    {
        $file = $this->testDir . 'nonexistent.php';

        $result = $this->appender->append($file, "Plugin::load('Test');");

        $this->assertFalse($result->success);
        $this->assertEquals('error', $result->status);
        $this->assertStringContainsString('File does not exist', $result->message);
    }

    public function testDryRunMode(): void
    {
        $file = $this->testDir . 'bootstrap.php';
        $originalContent = "<?php\n\n// Existing content\n";
        file_put_contents($file, $originalContent);

        $result = $this->appender->append($file, "Plugin::load('Test');", null, true);

        $this->assertTrue($result->success);
        $this->assertEquals('would-append', $result->status);
        $this->assertEquals($originalContent, file_get_contents($file));
    }

    public function testAppendWithoutMarker(): void
    {
        $file = $this->testDir . 'bootstrap.php';
        file_put_contents($file, "<?php\n\n// Existing content\n");

        $result = $this->appender->append($file, "Configure::write('debug', true);");

        $this->assertTrue($result->success);

        $content = file_get_contents($file);
        $this->assertStringContainsString("Configure::write('debug', true);", $content);
    }

    public function testAppendMultipleLines(): void
    {
        $file = $this->testDir . 'bootstrap.php';
        file_put_contents($file, "<?php\n\n// Existing content\n");

        $content = "// Load plugin\nPlugin::load('Test', ['bootstrap' => true]);\n// Configure plugin\nConfigure::write('Test.enabled', true);";

        $result = $this->appender->append($file, $content, '// Test plugin section');

        $this->assertTrue($result->success);

        $fileContent = file_get_contents($file);
        $this->assertStringContainsString('// Test plugin section', $fileContent);
        $this->assertStringContainsString("Plugin::load('Test'", $fileContent);
        $this->assertStringContainsString("Configure::write('Test.enabled'", $fileContent);
    }
}
