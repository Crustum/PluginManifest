<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Test\TestCase\Manifest;

use Cake\Core\Plugin;
use Cake\TestSuite\TestCase;
use Crustum\PluginManifest\Command\Helper\PluginDiscovery;
use Crustum\PluginManifest\Test\TestPlugin\TestPlugin;
use Exception;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Inspector-backed discovery test case.
 */
class InspectorDiscoveryTest extends TestCase
{
    /**
     * @var list<string>
     */
    protected array $tempDirs = [];

    /**
     * @var list<string>
     */
    protected array $addedPlugins = [];

    protected function tearDown(): void
    {
        foreach ($this->addedPlugins as $name) {
            try {
                Plugin::getCollection()->remove($name);
            } catch (Exception) {
            }
        }

        $this->addedPlugins = [];

        foreach ($this->tempDirs as $dir) {
            $this->removeDir($dir);
        }

        $this->tempDirs = [];

        parent::tearDown();
    }

    public function testDiscoverRawFindsLoadedPlugin(): void
    {
        $plugin = new TestPlugin();
        Plugin::getCollection()->add($plugin);
        $name = $plugin->getName();
        $this->addedPlugins[] = $name;

        $discovery = new PluginDiscovery();
        $found = $discovery->discoverRaw();

        $this->assertArrayHasKey($name, $found);
        $this->assertSame(TestPlugin::class, $found[$name]['class']);
        $this->assertTrue($found[$name]['loaded']);
        $this->assertNotEmpty($found[$name]['manifest']);
    }

    public function testDiscoverRawFindsUnloadedPluginViaExtraPath(): void
    {
        $dir = $this->makePluginDir(
            'ManifestFixture\\Unloaded',
            'UnloadedPlugin',
            'Unloaded',
            true,
        );

        $discovery = new PluginDiscovery();
        $found = $discovery->discoverRaw([$dir]);

        $this->assertArrayHasKey('ManifestFixture/Unloaded', $found);
        $this->assertSame('ManifestFixture\\Unloaded\\UnloadedPlugin', $found['ManifestFixture/Unloaded']['class']);
        $this->assertFalse($found['ManifestFixture/Unloaded']['loaded']);
        $this->assertNotEmpty($found['ManifestFixture/Unloaded']['manifest']);
    }

    public function testDiscoverRawFindsUnloadedProviderStyleManifest(): void
    {
        $dir = $this->makePluginDir(
            'ManifestFixture\\Bare',
            'BarePlugin',
            'Bare',
            false,
        );
        $manifestDir = $dir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Manifest';
        mkdir($manifestDir, 0777, true);
        $providerFile = $manifestDir . DIRECTORY_SEPARATOR . 'BareManifest.php';
        file_put_contents(
            $providerFile,
            "<?php\ndeclare(strict_types=1);\n\nnamespace ManifestFixture\\Bare\\Manifest;\n\n" .
            "class BareManifest\n{\n    public static function manifest(): array\n    {\n" .
            "        return [['type' => 'copy', 'source' => 'x', 'destination' => 'y', 'tag' => 'config']];\n" .
            "    }\n}\n",
        );
        require_once $providerFile;

        $discovery = new PluginDiscovery();
        $found = $discovery->discoverRaw([$dir]);

        $this->assertArrayHasKey('ManifestFixture/Bare', $found);
        $this->assertSame('ManifestFixture\\Bare\\BarePlugin', $found['ManifestFixture/Bare']['class']);
        $this->assertFalse($found['ManifestFixture/Bare']['loaded']);
        $this->assertNotEmpty($found['ManifestFixture/Bare']['manifest']);
    }

    public function testDiscoverRawFindsComposerInstalledButUnloadedPlugin(): void
    {
        $base = $this->tempDir();
        $packageDir = $base . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'acme' . DIRECTORY_SEPARATOR . 'locked';
        mkdir($packageDir . DIRECTORY_SEPARATOR . 'src', 0777, true);
        file_put_contents(
            $packageDir . DIRECTORY_SEPARATOR . 'composer.json',
            (string)json_encode([
                'name' => 'acme/locked',
                'type' => 'cakephp-plugin',
                'autoload' => ['psr-4' => ['ManifestFixture\\Locked\\' => 'src/']],
            ]),
        );
        file_put_contents(
            $packageDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'LockedPlugin.php',
            "<?php\ndeclare(strict_types=1);\n\nnamespace ManifestFixture\\Locked;\n\n" .
            "use Cake\\Core\\BasePlugin;\n" .
            "use Crustum\\PluginManifest\\Manifest\\ManifestInterface;\n\n" .
            "class LockedPlugin extends BasePlugin implements ManifestInterface\n{\n" .
            "    protected ?string \$name = 'Locked';\n\n" .
            "    public static function manifest(): array\n    {\n" .
            "        return [['type' => 'copy', 'source' => 'x', 'destination' => 'y', 'tag' => 'config']];\n" .
            "    }\n}\n",
        );
        file_put_contents(
            $base . DIRECTORY_SEPARATOR . 'composer.lock',
            (string)json_encode([
                'packages' => [['name' => 'acme/locked', 'version' => 'v1.0.0', 'type' => 'cakephp-plugin']],
                'packages-dev' => [],
            ]),
        );

        $discovery = new PluginDiscovery();
        $found = $discovery->discoverRaw([], $base);

        $this->assertArrayHasKey('ManifestFixture/Locked', $found);
        $this->assertSame('ManifestFixture\\Locked\\LockedPlugin', $found['ManifestFixture/Locked']['class']);
        $this->assertFalse($found['ManifestFixture/Locked']['loaded']);
    }

    /**
     * Scaffold a fake plugin directory with an interface-based manifest.
     *
     * @return string Plugin directory
     */
    protected function makePluginDir(string $namespace, string $short, string $cakeName, bool $withManifest): string
    {
        $dir = $this->tempDir();
        mkdir($dir . DIRECTORY_SEPARATOR . 'src', 0777, true);
        file_put_contents(
            $dir . DIRECTORY_SEPARATOR . 'composer.json',
            (string)json_encode([
                'name' => 'acme/' . strtolower($short),
                'type' => 'cakephp-plugin',
                'autoload' => ['psr-4' => [$namespace . '\\' => 'src/']],
            ]),
        );

        if ($withManifest) {
            $body = "use Cake\\Core\\BasePlugin;\n" .
                "use Crustum\\PluginManifest\\Manifest\\ManifestInterface;\n\n" .
                "class {$short} extends BasePlugin implements ManifestInterface\n{\n" .
                "    protected ?string \$name = '{$cakeName}';\n\n" .
                "    public static function manifest(): array\n    {\n" .
                "        return [['type' => 'copy', 'source' => 'x', 'destination' => 'y', 'tag' => 'config']];\n" .
                "    }\n}";
        } else {
            $body = "use Cake\\Core\\BasePlugin;\n\nclass {$short} extends BasePlugin\n{\n" .
                "    protected ?string \$name = '{$cakeName}';\n}";
        }

        file_put_contents(
            $dir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . $short . '.php',
            "<?php\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\n{$body}\n",
        );

        return $dir;
    }

    /**
     * Create a tracked temp directory.
     *
     * @return string
     */
    protected function tempDir(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'manifest_disc_' . uniqid();
        mkdir($dir, 0777, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /**
     * Remove a directory recursively.
     *
     * @param string $dir Directory
     * @return void
     */
    protected function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
