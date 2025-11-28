<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Test\TestCase\Manifest;

use Cake\TestSuite\TestCase;
use Crustum\PluginManifest\Manifest\ManifestRegistry;

/**
 * Dependency Tracking Test Case
 *
 * Tests the dependency tracking in ManifestRegistry
 */
class DependencyTrackingTest extends TestCase
{
    protected ManifestRegistry $registry;
    protected string $testRegistryFile;

    public function setUp(): void
    {
        parent::setUp();

        $this->testRegistryFile = TMP . 'tests' . DS . 'test_manifest_registry_' . uniqid() . '.php';

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
        if (file_exists($this->testRegistryFile)) {
            unlink($this->testRegistryFile);
        }

        unset($this->registry);
        parent::tearDown();
    }

    /**
     * Test recording dependency relationship
     */
    public function testRecordDependency(): void
    {
        $this->registry->recordDependency(
            'ParentPlugin',
            'DependencyPlugin',
            [
                'required' => true,
                'tags' => ['config', 'migrations'],
                'reason' => 'Test dependency',
            ],
        );

        $dependencies = $this->registry->getDependencies('ParentPlugin');

        $this->assertArrayHasKey('DependencyPlugin', $dependencies);
        $this->assertTrue($dependencies['DependencyPlugin']['required']);
        $this->assertEquals(['config', 'migrations'], $dependencies['DependencyPlugin']['tags']);
        $this->assertEquals('Test dependency', $dependencies['DependencyPlugin']['reason']);
        $this->assertArrayHasKey('installed_at', $dependencies['DependencyPlugin']);
    }

    /**
     * Test recording multiple dependencies
     */
    public function testRecordMultipleDependencies(): void
    {
        $this->registry->recordDependency('ParentPlugin', 'DepA', ['required' => true]);
        $this->registry->recordDependency('ParentPlugin', 'DepB', ['required' => false]);
        $this->registry->recordDependency('ParentPlugin', 'DepC', ['required' => true]);

        $dependencies = $this->registry->getDependencies('ParentPlugin');

        $this->assertCount(3, $dependencies);
        $this->assertArrayHasKey('DepA', $dependencies);
        $this->assertArrayHasKey('DepB', $dependencies);
        $this->assertArrayHasKey('DepC', $dependencies);
    }

    /**
     * Test getting dependents of a plugin
     */
    public function testGetDependents(): void
    {
        $this->registry->recordDependency('PluginA', 'CorePlugin', ['required' => true]);
        $this->registry->recordDependency('PluginB', 'CorePlugin', ['required' => true]);
        $this->registry->recordDependency('PluginC', 'CorePlugin', ['required' => false]);

        $dependents = $this->registry->getDependents('CorePlugin');

        $this->assertCount(3, $dependents);
        $this->assertContains('PluginA', $dependents);
        $this->assertContains('PluginB', $dependents);
        $this->assertContains('PluginC', $dependents);
    }

    /**
     * Test hasDependencies check
     */
    public function testHasDependencies(): void
    {
        $this->assertFalse($this->registry->hasDependencies('PluginA'));

        $this->registry->recordDependency('PluginA', 'DepPlugin', ['required' => true]);

        $this->assertTrue($this->registry->hasDependencies('PluginA'));
        $this->assertFalse($this->registry->hasDependencies('PluginB'));
    }

    /**
     * Test getPluginStatus with dependencies
     */
    public function testGetPluginStatusWithDependencies(): void
    {
        $this->registry->recordInstalled('TestPlugin', 'copy', 'config', [
            'source' => '/test/source',
            'destination' => '/test/dest',
        ]);

        $this->registry->recordDependency('TestPlugin', 'DepPlugin', [
            'required' => true,
            'tags' => ['config'],
        ]);

        $status = $this->registry->getPluginStatus('TestPlugin');

        $this->assertTrue($status['installed']);
        $this->assertArrayHasKey('operations', $status);
        $this->assertArrayHasKey('dependencies', $status);
        $this->assertArrayHasKey('DepPlugin', $status['dependencies']);
    }

    /**
     * Test getPluginStatus for plugin with no dependencies
     */
    public function testGetPluginStatusNoDependencies(): void
    {
        $status = $this->registry->getPluginStatus('NonExistentPlugin');

        $this->assertFalse($status['installed']);
        $this->assertEmpty($status['operations']);
        $this->assertEmpty($status['dependencies']);
    }

    /**
     * Test dependency chain tracking
     */
    public function testDependencyChainTracking(): void
    {
        $this->registry->recordDependency('PluginA', 'PluginB', ['required' => true]);
        $this->registry->recordDependency('PluginA', 'PluginC', ['required' => false]);
        $this->registry->recordDependency('PluginB', 'PluginD', ['required' => true]);

        $aDeps = $this->registry->getDependencies('PluginA');
        $this->assertCount(2, $aDeps);

        $bDeps = $this->registry->getDependencies('PluginB');
        $this->assertCount(1, $bDeps);

        $dDependents = $this->registry->getDependents('PluginD');
        $this->assertContains('PluginB', $dDependents);
    }
}
