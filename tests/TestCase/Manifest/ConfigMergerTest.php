<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Test\TestCase\Manifest;

use Cake\TestSuite\TestCase;
use Crustum\PluginManifest\Manifest\ConfigMerger;
use Crustum\PluginManifest\Manifest\RawValue;

class ConfigMergerTest extends TestCase
{
    protected string $testDir;
    protected ConfigMerger $merger;

    public function setUp(): void
    {
        parent::setUp();

        $this->testDir = TMP . 'tests' . DS . 'config_' . uniqid() . DS;
        mkdir($this->testDir, 0777, true);

        $this->merger = new ConfigMerger();
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

    public function testMergeConfiguration(): void
    {
        $file = $this->testDir . 'app.php';
        file_put_contents($file, "<?php\n\nreturn [\n    'existing' => 'value',\n];\n");

        $result = $this->merger->merge($file, 'TestPlugin', ['enabled' => true, 'timeout' => 30]);

        $this->assertTrue($result->success);
        $this->assertEquals('merged', $result->status);

        $config = require $file;
        $this->assertArrayHasKey('TestPlugin', $config);
        $this->assertEquals(['enabled' => true, 'timeout' => 30], $config['TestPlugin']);
        $this->assertEquals('value', $config['existing']);
    }

    public function testSkipWhenKeyExists(): void
    {
        $file = $this->testDir . 'app.php';
        file_put_contents($file, "<?php\n\nreturn [\n    'TestPlugin' => ['old' => 'value'],\n];\n");

        $result = $this->merger->merge($file, 'TestPlugin', ['enabled' => true]);

        $this->assertFalse($result->success);
        $this->assertEquals('skipped', $result->status);
        $this->assertStringContainsString('already exists', $result->message);
    }

    public function testErrorWhenFileDoesNotExist(): void
    {
        $file = $this->testDir . 'nonexistent.php';

        $result = $this->merger->merge($file, 'TestPlugin', ['enabled' => true]);

        $this->assertFalse($result->success);
        $this->assertEquals('error', $result->status);
        $this->assertStringContainsString('File does not exist', $result->message);
    }

    public function testDryRunMode(): void
    {
        $file = $this->testDir . 'app.php';
        $originalContent = "<?php\n\nreturn [\n    'existing' => 'value',\n];\n";
        file_put_contents($file, $originalContent);

        $result = $this->merger->merge($file, 'TestPlugin', ['enabled' => true], true);

        $this->assertTrue($result->success);
        $this->assertEquals('would-merge', $result->status);
        $this->assertEquals($originalContent, file_get_contents($file));
    }

    public function testPreservesComments(): void
    {
        $file = $this->testDir . 'app.php';
        $content = "<?php\n\n// Important comment\nreturn [\n    // Another comment\n    'existing' => 'value',\n];\n";
        file_put_contents($file, $content);

        $result = $this->merger->merge($file, 'TestPlugin', ['enabled' => true]);

        $this->assertTrue($result->success);

        $newContent = file_get_contents($file);
        $this->assertStringContainsString('// Important comment', $newContent);
        $this->assertStringContainsString('// Another comment', $newContent);
    }

    public function testMergeComplexValue(): void
    {
        $file = $this->testDir . 'app.php';
        file_put_contents($file, "<?php\n\nreturn [\n    'existing' => 'value',\n];\n");

        $complexValue = [
            'database' => [
                'host' => 'localhost',
                'port' => 3306,
            ],
            'cache' => [
                'enabled' => true,
                'duration' => 3600,
            ],
            'features' => ['feature1', 'feature2'],
        ];

        $result = $this->merger->merge($file, 'TestPlugin', $complexValue);

        $this->assertTrue($result->success);

        $config = require $file;
        $this->assertEquals($complexValue, $config['TestPlugin']);
    }

    public function testMergeDeeplyNestedArrays(): void
    {
        $file = $this->testDir . 'app.php';
        file_put_contents($file, "<?php\n\n// Main config\nreturn [\n    'existing' => 'value',\n];\n");

        $deeplyNested = [
            'level1' => [
                'level2' => [
                    'level3' => [
                        'value' => 'deep',
                        'number' => 42,
                    ],
                ],
            ],
        ];

        $result = $this->merger->merge($file, 'TestPlugin', $deeplyNested);

        $this->assertTrue($result->success);

        $config = require $file;
        $this->assertEquals($deeplyNested, $config['TestPlugin']);

        $content = file_get_contents($file);
        $this->assertStringContainsString('// Main config', $content);
        $this->assertStringContainsString("'existing' => 'value',", $content);
    }

    public function testMergePreservesCommentsWithNestedArrays(): void
    {
        $file = $this->testDir . 'app.php';
        $originalContent = "<?php\n\n// Important header\nreturn [\n    // First setting\n    'setting1' => 'value1',\n    // Second setting\n    'setting2' => 'value2',\n];\n";
        file_put_contents($file, $originalContent);

        $nestedValue = [
            'database' => [
                'host' => 'localhost',
                'credentials' => [
                    'username' => 'admin',
                    'password' => 'secret',
                ],
            ],
        ];

        $result = $this->merger->merge($file, 'TestPlugin', $nestedValue);

        $this->assertTrue($result->success);

        $content = file_get_contents($file);
        $this->assertStringContainsString('// Important header', $content);
        $this->assertStringContainsString('// First setting', $content);
        $this->assertStringContainsString('// Second setting', $content);
        $this->assertStringContainsString("'setting1' => 'value1',", $content);
        $this->assertStringContainsString("'setting2' => 'value2',", $content);
        $this->assertStringContainsString('TestPlugin', $content);

        $config = require $file;
        $this->assertEquals($nestedValue, $config['TestPlugin']);
        $this->assertEquals('value1', $config['setting1']);
        $this->assertEquals('value2', $config['setting2']);
    }

    public function testMergeArrayWithMixedTypes(): void
    {
        $file = $this->testDir . 'app.php';
        file_put_contents($file, "<?php\n\nreturn [\n];\n");

        $mixedValue = [
            'string' => 'text',
            'number' => 123,
            'boolean' => true,
            'null' => null,
            'array' => ['a', 'b', 'c'],
            'assoc' => ['key1' => 'val1', 'key2' => 'val2'],
        ];

        $result = $this->merger->merge($file, 'TestPlugin', $mixedValue);

        $this->assertTrue($result->success);

        $config = require $file;
        $this->assertEquals($mixedValue, $config['TestPlugin']);
        $this->assertSame('text', $config['TestPlugin']['string']);
        $this->assertSame(123, $config['TestPlugin']['number']);
        $this->assertSame(true, $config['TestPlugin']['boolean']);
        $this->assertSame(null, $config['TestPlugin']['null']);
    }

    public function testMergeDeepNestedPath(): void
    {
        $file = $this->testDir . 'notification.php';
        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'Notification' => [\n        'channels' => [\n            'database' => [\n                'className' => 'Crustum/Notification.Database',\n            ],\n            'mail' => [\n                'className' => 'Crustum/Notification.Mail',\n                'profile' => 'default',\n            ],\n        ],\n    ],\n];\n";
        file_put_contents($file, $content);

        $slackConfig = [
            'className' => 'Crustum/NotificationSlack.Slack',
            'webhook_url' => RawValue::raw("env('SLACK_WEBHOOK_URL')"),
        ];

        $result = $this->merger->merge($file, 'Notification.channels.slack', $slackConfig);

        $this->assertTrue($result->success);
        $this->assertEquals('merged', $result->status);

        $config = require $file;
        $this->assertArrayHasKey('Notification', $config);
        $this->assertArrayHasKey('channels', $config['Notification']);
        $this->assertArrayHasKey('slack', $config['Notification']['channels']);
        $this->assertEquals('Crustum/NotificationSlack.Slack', $config['Notification']['channels']['slack']['className']);

        $fileContent = file_get_contents($file);
        $this->assertStringContainsString("env('SLACK_WEBHOOK_URL')", $fileContent);
        $this->assertStringNotContainsString('NULL', $fileContent);
    }

    public function testMergeWithRawValue(): void
    {
        $file = $this->testDir . 'app.php';
        file_put_contents($file, "<?php\n\nreturn [\n];\n");

        $config = [
            'host' => RawValue::raw("env('MONITOR_REDIS_HOST', '127.0.0.1')"),
            'port' => 6379,
            'password' => RawValue::raw("env('MONITOR_REDIS_PASSWORD')"),
        ];

        $result = $this->merger->merge($file, 'Redis', $config);

        $this->assertTrue($result->success);

        $fileContent = file_get_contents($file);
        $this->assertStringContainsString("env('MONITOR_REDIS_HOST', '127.0.0.1')", $fileContent);
        $this->assertStringContainsString("env('MONITOR_REDIS_PASSWORD')", $fileContent);
        $this->assertStringContainsString("'port' => 6379", $fileContent);
        $this->assertStringNotContainsString('NULL', $fileContent);

        $loadedConfig = require $file;
        $this->assertArrayHasKey('Redis', $loadedConfig);
        $this->assertEquals(6379, $loadedConfig['Redis']['port']);
    }
}
