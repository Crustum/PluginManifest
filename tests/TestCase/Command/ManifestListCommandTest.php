<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Test\TestCase\Command;

use Cake\TestSuite\TestCase;
use Crustum\PluginManifest\Command\ManifestListCommand;
use Crustum\PluginManifest\Manifest\ManifestRegistry;
use ReflectionClass;
use ReflectionMethod;

/**
 * Manifest list command test case.
 */
class ManifestListCommandTest extends TestCase
{
    protected string $testRegistryFile;

    protected ManifestRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testRegistryFile = TMP . 'test_list_registry_' . uniqid() . '.php';

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
                $content = "<?php\n\nreturn " . var_export($manifest, true) . ";\n";
                file_put_contents($this->manifestFile, $content);
            }
        };
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (file_exists($this->testRegistryFile)) {
            unlink($this->testRegistryFile);
        }
    }

    public function testSummarizeTagCountsBucketOncePerType(): void
    {
        $this->registry->recordInstalled('TestPlugin', 'copy-safe', 'config', [
            'destination' => 'config/a.php',
        ]);
        $this->registry->recordInstalled('TestPlugin', 'copy-safe', 'config', [
            'destination' => 'config/b.php',
        ]);

        $tagAssets = [
            ['type' => 'copy-safe', 'tag' => 'config'],
            ['type' => 'copy-safe', 'tag' => 'config'],
        ];

        $summary = $this->summarizeTag('config', $tagAssets, 'TestPlugin');

        $this->assertSame(['installed' => 2, 'total' => 2], $summary);
    }

    public function testSummarizeTagSkipsStarRepo(): void
    {
        $tagAssets = [
            ['type' => 'star-repo', 'tag' => 'star-repo'],
        ];

        $this->assertNull($this->summarizeTag('star-repo', $tagAssets, 'TestPlugin'));

        $mixedAssets = [
            ['type' => 'copy-safe', 'tag' => 'config'],
            ['type' => 'star-repo', 'tag' => 'config'],
        ];

        $summary = $this->summarizeTag('config', $mixedAssets, 'TestPlugin');

        $this->assertSame(['installed' => 0, 'total' => 1], $summary);
    }

    /**
     * Invoke the protected tag summarizer.
     *
     * @param string $tag Tag name
     * @param array<int, array<string, mixed>> $tagAssets Tag assets
     * @param string $pluginName Plugin name
     * @return array{installed: int, total: int}|null
     */
    protected function summarizeTag(string $tag, array $tagAssets, string $pluginName): ?array
    {
        $command = new ReflectionMethod(ManifestListCommand::class, 'summarizeTag');

        return $command->invokeArgs(
            (new ReflectionClass(ManifestListCommand::class))->newInstanceWithoutConstructor(),
            [$tag, $tagAssets, $pluginName, $this->registry],
        );
    }
}
