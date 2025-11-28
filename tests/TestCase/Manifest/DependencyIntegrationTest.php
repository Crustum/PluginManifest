<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Test\TestCase\Manifest;

use Cake\TestSuite\TestCase;
use Crustum\PluginManifest\Manifest\DependencyResolver;
use Crustum\PluginManifest\Manifest\ManifestTrait;
use Crustum\PluginManifest\Manifest\OperationType;

/**
 * Dependency Integration Test Case
 *
 * Tests the integration between ManifestTrait helpers and DependencyResolver
 */
class DependencyIntegrationTest extends TestCase
{
    use ManifestTrait;

    protected DependencyResolver $resolver;

    public function setUp(): void
    {
        parent::setUp();
        $this->resolver = new DependencyResolver();
    }

    public function tearDown(): void
    {
        unset($this->resolver);
        parent::tearDown();
    }

    /**
     * Test manifestDependencies helper method
     */
    public function testManifestDependenciesHelper(): void
    {
        $dependencies = [
            'TestPluginA' => [
                'required' => true,
                'tags' => ['config'],
                'reason' => 'Required for core functionality',
            ],
            'TestPluginB' => [
                'required' => false,
                'tags' => ['config', 'migrations'],
                'prompt' => 'Install TestPluginB for enhanced features?',
                'reason' => 'Provides additional capabilities',
            ],
        ];

        $result = static::manifestDependencies($dependencies);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);

        $asset = $result[0];
        $this->assertEquals(OperationType::DEPENDENCIES, $asset['type']);
        $this->assertEquals('dependencies', $asset['tag']);
        $this->assertEquals($dependencies, $asset['dependencies']);
        $this->assertArrayHasKey('plugin', $asset);
    }

    /**
     * Test dependency resolution with manifest helper output
     */
    public function testDependencyResolutionWithManifestHelper(): void
    {
        // Create dependency definitions using the helper
        $pluginADeps = static::manifestDependencies([
            'TestPluginB' => [
                'required' => true,
                'tags' => ['config'],
            ],
        ]);

        $pluginBDeps = static::manifestDependencies([]);

        // Extract dependencies for resolver
        $allDependencies = [
            'TestPluginA' => [
                'dependencies' => $pluginADeps[0]['dependencies'],
            ],
            'TestPluginB' => [
                'dependencies' => $pluginBDeps[0]['dependencies'],
            ],
        ];

        $order = $this->resolver->resolveDependencyOrder($allDependencies);

        $this->assertEquals(['TestPluginB', 'TestPluginA'], $order);
    }

    /**
     * Test dependency filtering with different configurations
     */
    public function testDependencyFilteringScenarios(): void
    {
        $dependencies = [
            'RequiredPlugin' => [
                'required' => true,
                'tags' => ['config'],
                'reason' => 'Always needed',
            ],
            'OptionalPlugin' => [
                'required' => false,
                'tags' => ['config'],
                'reason' => 'Nice to have',
            ],
            'ConditionalPlugin' => [
                'required' => false,
                'condition' => function () {

                    return true;
                },
                'tags' => ['migrations'],
                'reason' => 'Needed when condition is met',
            ],
            'ConditionalPluginFalse' => [
                'required' => false,
                'condition' => function () {

                    return false;
                },
                'tags' => ['migrations'],
                'reason' => 'Not needed when condition is false',
            ],
        ];

        // Test default filtering (required only)
        $filtered = $this->resolver->filterDependencies($dependencies);
        $this->assertArrayHasKey('RequiredPlugin', $filtered);
        $this->assertArrayNotHasKey('OptionalPlugin', $filtered);
        $this->assertArrayNotHasKey('ConditionalPlugin', $filtered);
        $this->assertArrayNotHasKey('ConditionalPluginFalse', $filtered);

        // Test with optional plugins
        $filtered = $this->resolver->filterDependencies($dependencies, [
            'install_optional' => true,
        ]);
        $this->assertArrayHasKey('RequiredPlugin', $filtered);
        $this->assertArrayHasKey('OptionalPlugin', $filtered);
        $this->assertArrayHasKey('ConditionalPlugin', $filtered);
        $this->assertArrayNotHasKey('ConditionalPluginFalse', $filtered);

        // Test force all
        $filtered = $this->resolver->filterDependencies($dependencies, [
            'force_all' => true,
        ]);
        $this->assertArrayHasKey('RequiredPlugin', $filtered);
        $this->assertArrayHasKey('OptionalPlugin', $filtered);
        $this->assertArrayHasKey('ConditionalPlugin', $filtered);
        $this->assertArrayHasKey('ConditionalPluginFalse', $filtered);
    }

    /**
     * Test dependency info extraction
     */
    public function testDependencyInfoExtraction(): void
    {
        $dependencies = [
            'TestPlugin' => [
                'required' => true,
                'tags' => ['config', 'migrations'],
                'reason' => 'Core functionality provider',
                'prompt' => 'Install TestPlugin for core features?',
            ],
        ];

        $info = $this->resolver->getDependencyInfo($dependencies);

        $this->assertArrayHasKey('TestPlugin', $info);

        $pluginInfo = $info['TestPlugin'];
        $this->assertTrue($pluginInfo['required']);
        $this->assertEquals(['config', 'migrations'], $pluginInfo['tags']);
        $this->assertEquals('Core functionality provider', $pluginInfo['reason']);
        $this->assertEquals('Install TestPlugin for core features?', $pluginInfo['prompt']);
        $this->assertTrue($pluginInfo['condition_met']);
    }

    /**
     * Test complex dependency chain
     */
    public function testComplexDependencyChain(): void
    {
        // Create a complex dependency chain: A -> B -> C, A -> D -> C
        $allDependencies = [
            'PluginA' => [
                'dependencies' => [
                    'PluginB' => ['required' => true],
                    'PluginD' => ['required' => true],
                ],
            ],
            'PluginB' => [
                'dependencies' => [
                    'PluginC' => ['required' => true],
                ],
            ],
            'PluginC' => [
                'dependencies' => [],
            ],
            'PluginD' => [
                'dependencies' => [
                    'PluginC' => ['required' => true],
                ],
            ],
        ];

        $order = $this->resolver->resolveDependencyOrder($allDependencies);

        // PluginC should be first (no dependencies)
        $this->assertEquals('PluginC', $order[0]);

        // PluginA should be last (depends on everything)
        $this->assertEquals('PluginA', $order[count($order) - 1]);

        // B and D should come after C but before A
        $cIndex = array_search('PluginC', $order);
        $bIndex = array_search('PluginB', $order);
        $dIndex = array_search('PluginD', $order);
        $aIndex = array_search('PluginA', $order);

        $this->assertGreaterThan($cIndex, $bIndex);
        $this->assertGreaterThan($cIndex, $dIndex);
        $this->assertGreaterThan($bIndex, $aIndex);
        $this->assertGreaterThan($dIndex, $aIndex);
    }

    /**
     * Test manifest helper with real-world scenario
     */
    public function testRealWorldScenario(): void
    {
        // Simulate a plugin that needs core functionality and optional features
        $manifest = array_merge(
            static::manifestConfig(
                '/fake/path/config.php',
                CONFIG . 'test_plugin.php',
            ),
            static::manifestDependencies([
                'CorePlugin' => [
                    'required' => true,
                    'tags' => ['config', 'migrations'],
                    'reason' => 'Provides essential core functionality',
                ],
                'LoggingPlugin' => [
                    'required' => false,
                    'tags' => ['config'],
                    'prompt' => 'Install logging capabilities?',
                    'reason' => 'Enhanced logging and debugging features',
                ],
                'CachePlugin' => [
                    'required' => false,
                    'condition' => 'config_exists',
                    'condition_key' => 'Cache.default',
                    'tags' => ['config'],
                    'reason' => 'Performance optimization through caching',
                ],
            ]),
        );

        $this->assertCount(2, $manifest);

        // Check config asset
        $configAsset = $manifest[0];
        $this->assertEquals('copy-safe', $configAsset['type']);
        $this->assertEquals('config', $configAsset['tag']);

        // Check dependencies asset
        $depsAsset = $manifest[1];
        $this->assertEquals(OperationType::DEPENDENCIES, $depsAsset['type']);
        $this->assertEquals('dependencies', $depsAsset['tag']);
        $this->assertCount(3, $depsAsset['dependencies']);

        // Verify dependency structure
        $deps = $depsAsset['dependencies'];
        $this->assertTrue($deps['CorePlugin']['required']);
        $this->assertFalse($deps['LoggingPlugin']['required']);
        $this->assertFalse($deps['CachePlugin']['required']);
        $this->assertEquals('config_exists', $deps['CachePlugin']['condition']);
    }
}
