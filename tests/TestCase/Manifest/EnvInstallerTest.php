<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Test\TestCase\Manifest;

use Cake\TestSuite\TestCase;
use Crustum\PluginManifest\Manifest\EnvInstaller;

class EnvInstallerTest extends TestCase
{
    protected string $testDir;
    protected EnvInstaller $installer;

    public function setUp(): void
    {
        parent::setUp();

        $this->testDir = TMP . 'tests' . DS . 'env_' . uniqid() . DS;
        mkdir($this->testDir, 0777, true);

        $this->installer = new EnvInstaller();
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

    public function testAppendEnvVars(): void
    {
        $file = $this->testDir . '.env';
        file_put_contents($file, "EXISTING_VAR=value\n");

        $vars = [
            'NEW_VAR' => 'new_value',
            'ANOTHER_VAR' => 'another_value',
        ];

        $result = $this->installer->appendVars($file, $vars, '# Test variables');

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Added 2 variable(s)', $result->message);

        $content = file_get_contents($file);
        $this->assertStringContainsString('# Test variables', $content);
        $this->assertStringContainsString('NEW_VAR=new_value', $content);
        $this->assertStringContainsString('ANOTHER_VAR=another_value', $content);
        $this->assertStringContainsString('EXISTING_VAR=value', $content);
    }

    public function testSkipsExistingVars(): void
    {
        $file = $this->testDir . '.env';
        file_put_contents($file, "EXISTING_VAR=value\nNEW_VAR=old_value\n");

        $vars = [
            'NEW_VAR' => 'new_value',
            'ANOTHER_VAR' => 'another_value',
        ];

        $result = $this->installer->appendVars($file, $vars);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Added 1 variable(s), skipped 1', $result->message);

        $content = file_get_contents($file);
        $this->assertStringContainsString('ANOTHER_VAR=another_value', $content);
        $this->assertStringContainsString('NEW_VAR=old_value', $content);
        $this->assertStringNotContainsString('NEW_VAR=new_value', $content);
    }

    public function testCreatesFileIfNotExists(): void
    {
        $file = $this->testDir . '.env';

        $vars = ['NEW_VAR' => 'value'];

        $result = $this->installer->appendVars($file, $vars);

        $this->assertTrue($result->success);
        $this->assertFileExists($file);

        $content = file_get_contents($file);
        $this->assertStringContainsString('NEW_VAR=value', $content);
    }

    public function testDryRunMode(): void
    {
        $file = $this->testDir . '.env';
        $originalContent = "EXISTING_VAR=value\n";
        file_put_contents($file, $originalContent);

        $vars = ['NEW_VAR' => 'new_value'];

        $result = $this->installer->appendVars($file, $vars, null, true);

        $this->assertTrue($result->success);
        $this->assertEquals('would-add', $result->status);
        $this->assertEquals($originalContent, file_get_contents($file));
    }

    public function testHandlesValuesWithSpaces(): void
    {
        $file = $this->testDir . '.env';
        file_put_contents($file, '');

        $vars = [
            'VAR_WITH_SPACES' => 'value with spaces',
            'VAR_WITH_QUOTES' => 'value "with" quotes',
        ];

        $result = $this->installer->appendVars($file, $vars);

        $this->assertTrue($result->success);

        $content = file_get_contents($file);
        $this->assertStringContainsString('VAR_WITH_SPACES=value with spaces', $content);
        $this->assertStringContainsString('VAR_WITH_QUOTES=value "with" quotes', $content);
    }

    public function testEmptyVarsArray(): void
    {
        $file = $this->testDir . '.env';
        file_put_contents($file, "EXISTING_VAR=value\n");

        $result = $this->installer->appendVars($file, []);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Added 0 variable(s)', $result->message);
    }

    public function testAppendsComment(): void
    {
        $file = $this->testDir . '.env';
        file_put_contents($file, "EXISTING_VAR=value\n");

        $vars = ['NEW_VAR' => 'value'];
        $comment = '# Plugin configuration variables';

        $result = $this->installer->appendVars($file, $vars, $comment);

        $this->assertTrue($result->success);

        $content = file_get_contents($file);
        $lines = explode("\n", $content);

        $commentFound = false;
        $varFoundAfterComment = false;

        foreach ($lines as $line) {
            if (trim($line) === trim($comment)) {
                $commentFound = true;
            }
            if ($commentFound && str_contains($line, 'NEW_VAR=')) {
                $varFoundAfterComment = true;
                break;
            }
        }

        $this->assertTrue($commentFound, 'Comment should be in file');
        $this->assertTrue($varFoundAfterComment, 'Variable should appear after comment');
    }
}
