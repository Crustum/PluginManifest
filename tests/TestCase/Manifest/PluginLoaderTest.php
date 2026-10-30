<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Test\TestCase\Manifest;

use Cake\TestSuite\TestCase;
use Crustum\PluginManifest\Manifest\PluginLoader;

/**
 * Plugin loader test case.
 */
class PluginLoaderTest extends TestCase
{
    /**
     * @var list<string>
     */
    protected array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->tempFiles = [];

        parent::tearDown();
    }

    public function testEnsureLoadedRegistersPlugin(): void
    {
        $file = $this->tempFile('<?php' . "\n\n" . 'return [];');
        $loader = new PluginLoader($file);

        $result = $loader->ensureLoaded('TestPlugin', [], ['all_deps' => true]);

        $this->assertTrue($result->success);
        $this->assertSame('installed', $result->status);

        // phpcs:ignore
        $config = include $file;
        $this->assertSame([], $config['TestPlugin']);
    }

    public function testEnsureLoadedSkipsAlreadyRegistered(): void
    {
        $file = $this->tempFile('<?php' . "\n\n" . 'return [' . "\n    'TestPlugin' => [" . "\n        'bootstrap' => true," . "\n    ]," . "\n];");
        $loader = new PluginLoader($file);

        $result = $loader->ensureLoaded('TestPlugin', [], ['all_deps' => true]);

        $this->assertTrue($result->success);
        $this->assertSame('skipped', $result->status);
    }

    public function testEnsureLoadedCreatesMissingFile(): void
    {
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'manifest_loader_' . uniqid() . '.php';
        $this->tempFiles[] = $file;
        $loader = new PluginLoader($file);

        $result = $loader->ensureLoaded('TestPlugin', ['routes' => false], ['all_deps' => true]);

        $this->assertTrue($result->success);
        $this->assertFileExists($file);

        // phpcs:ignore
        $config = include $file;
        $this->assertSame(['routes' => false], $config['TestPlugin']);
    }

    public function testEnsureLoadedDryRunDoesNotWrite(): void
    {
        $file = $this->tempFile('<?php' . "\n\n" . 'return [];');
        $loader = new PluginLoader($file);
        $before = (string)file_get_contents($file);

        $result = $loader->ensureLoaded('TestPlugin', [], ['dry_run' => true]);

        $this->assertTrue($result->success);
        $this->assertSame('would-register', $result->status);
        $this->assertSame($before, (string)file_get_contents($file));
    }

    /**
     * Create a tracked temp plugins config file.
     *
     * @param string $contents File contents
     * @return string
     */
    protected function tempFile(string $contents): string
    {
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'manifest_loader_' . uniqid() . '.php';
        file_put_contents($file, $contents);
        $this->tempFiles[] = $file;

        return $file;
    }
}
