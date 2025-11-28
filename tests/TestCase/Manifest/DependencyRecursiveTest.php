<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Test\TestCase\Manifest;

use Cake\TestSuite\TestCase;
use Crustum\PluginManifest\Manifest\DependencyResolver;
use InvalidArgumentException;

/**
 * DependencyResolver Recursive Test Case
 *
 * Tests the recursive dependency discovery and installation order
 */
class DependencyRecursiveTest extends TestCase
{
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
     * Test recursive dependency tree building - two levels
     */
    public function testBuildDependencyTreeTwoLevels(): void
    {
        $availablePlugins = [
            'PluginA' => [
                'assets' => [
                    'dependencies' => [[
                        'type' => 'dependencies',
                        'dependencies' => [
                            'PluginB' => ['required' => true],
                        ],
                    ]],
                ],
            ],
            'PluginB' => [
                'assets' => [
                    'dependencies' => [[
                        'type' => 'dependencies',
                        'dependencies' => [
                            'PluginC' => ['required' => true],
                        ],
                    ]],
                ],
            ],
            'PluginC' => [
                'assets' => [
                    'config' => [[
                        'type' => 'copy',
                        'source' => '/fake',
                        'destination' => '/fake',
                    ]],
                ],
            ],
        ];

        $result = $this->resolver->buildDependencyTree($availablePlugins, ['PluginA'], []);

        $this->assertIsArray($result);
        $keys = array_keys($result);

        $this->assertCount(3, $keys);
        $this->assertEquals('PluginC', $keys[0]);
        $this->assertEquals('PluginB', $keys[1]);
        $this->assertEquals('PluginA', $keys[2]);
    }

    /**
     * Test recursive dependency tree building - three levels
     */
    public function testBuildDependencyTreeThreeLevels(): void
    {
        $availablePlugins = [
            'PluginA' => [
                'assets' => [
                    'dependencies' => [[
                        'type' => 'dependencies',
                        'dependencies' => [
                            'PluginB' => ['required' => true],
                        ],
                    ]],
                ],
            ],
            'PluginB' => [
                'assets' => [
                    'dependencies' => [[
                        'type' => 'dependencies',
                        'dependencies' => [
                            'PluginC' => ['required' => true],
                        ],
                    ]],
                ],
            ],
            'PluginC' => [
                'assets' => [
                    'dependencies' => [[
                        'type' => 'dependencies',
                        'dependencies' => [
                            'PluginD' => ['required' => true],
                        ],
                    ]],
                ],
            ],
            'PluginD' => [
                'assets' => [
                    'config' => [[
                        'type' => 'copy',
                        'source' => '/fake',
                        'destination' => '/fake',
                    ]],
                ],
            ],
        ];

        $result = $this->resolver->buildDependencyTree($availablePlugins, ['PluginA'], []);

        $this->assertIsArray($result);
        $keys = array_keys($result);

        $this->assertCount(4, $keys);
        $this->assertEquals('PluginD', $keys[0]);
        $this->assertEquals('PluginC', $keys[1]);
        $this->assertEquals('PluginB', $keys[2]);
        $this->assertEquals('PluginA', $keys[3]);
    }

    /**
     * Test diamond dependency pattern
     */
    public function testBuildDependencyTreeDiamond(): void
    {
        $availablePlugins = [
            'PluginA' => [
                'assets' => [
                    'dependencies' => [[
                        'type' => 'dependencies',
                        'dependencies' => [
                            'PluginB' => ['required' => true],
                            'PluginC' => ['required' => true],
                        ],
                    ]],
                ],
            ],
            'PluginB' => [
                'assets' => [
                    'dependencies' => [[
                        'type' => 'dependencies',
                        'dependencies' => [
                            'PluginD' => ['required' => true],
                        ],
                    ]],
                ],
            ],
            'PluginC' => [
                'assets' => [
                    'dependencies' => [[
                        'type' => 'dependencies',
                        'dependencies' => [
                            'PluginD' => ['required' => true],
                        ],
                    ]],
                ],
            ],
            'PluginD' => [
                'assets' => [
                    'config' => [[
                        'type' => 'copy',
                        'source' => '/fake',
                        'destination' => '/fake',
                    ]],
                ],
            ],
        ];

        $result = $this->resolver->buildDependencyTree($availablePlugins, ['PluginA'], []);

        $this->assertIsArray($result);
        $keys = array_keys($result);

        $this->assertCount(4, $keys);
        $this->assertEquals('PluginD', $keys[0]);
        $this->assertContains('PluginB', $keys);
        $this->assertContains('PluginC', $keys);
        $this->assertEquals('PluginA', $keys[3]);
    }

    /**
     * Test multiple root plugins
     */
    public function testBuildDependencyTreeMultipleRoots(): void
    {
        $availablePlugins = [
            'PluginA' => [
                'assets' => [
                    'dependencies' => [[
                        'type' => 'dependencies',
                        'dependencies' => [
                            'PluginC' => ['required' => true],
                        ],
                    ]],
                ],
            ],
            'PluginB' => [
                'assets' => [
                    'dependencies' => [[
                        'type' => 'dependencies',
                        'dependencies' => [
                            'PluginC' => ['required' => true],
                        ],
                    ]],
                ],
            ],
            'PluginC' => [
                'assets' => [
                    'config' => [[
                        'type' => 'copy',
                        'source' => '/fake',
                        'destination' => '/fake',
                    ]],
                ],
            ],
        ];

        $result = $this->resolver->buildDependencyTree($availablePlugins, ['PluginA', 'PluginB'], []);

        $this->assertIsArray($result);
        $keys = array_keys($result);

        $this->assertCount(3, $keys);
        $this->assertEquals('PluginC', $keys[0]);
        $this->assertContains('PluginA', $keys);
        $this->assertContains('PluginB', $keys);
    }

    /**
     * Test with optional dependencies
     */
    public function testBuildDependencyTreeWithOptional(): void
    {
        $availablePlugins = [
            'PluginA' => [
                'assets' => [
                    'dependencies' => [[
                        'type' => 'dependencies',
                        'dependencies' => [
                            'PluginB' => ['required' => true],
                            'PluginC' => ['required' => false],
                        ],
                    ]],
                ],
            ],
            'PluginB' => [
                'assets' => [
                    'config' => [[
                        'type' => 'copy',
                        'source' => '/fake',
                        'destination' => '/fake',
                    ]],
                ],
            ],
            'PluginC' => [
                'assets' => [
                    'config' => [[
                        'type' => 'copy',
                        'source' => '/fake',
                        'destination' => '/fake',
                    ]],
                ],
            ],
        ];

        $result = $this->resolver->buildDependencyTree($availablePlugins, ['PluginA'], []);

        $this->assertIsArray($result);
        $keys = array_keys($result);

        $this->assertCount(2, $keys);
        $this->assertEquals('PluginB', $keys[0]);
        $this->assertEquals('PluginA', $keys[1]);
        $this->assertNotContains('PluginC', $keys);
    }

    /**
     * Test with no dependencies
     */
    public function testBuildDependencyTreeNoDependencies(): void
    {
        $availablePlugins = [
            'PluginA' => [
                'assets' => [
                    'config' => [[
                        'type' => 'copy',
                        'source' => '/fake',
                        'destination' => '/fake',
                    ]],
                ],
            ],
        ];

        $result = $this->resolver->buildDependencyTree($availablePlugins, ['PluginA'], []);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertArrayHasKey('PluginA', $result);
    }

    /**
     * Test circular dependency detection in recursive tree
     */
    public function testBuildDependencyTreeCircularDependency(): void
    {
        $availablePlugins = [
            'PluginA' => [
                'assets' => [
                    'dependencies' => [[
                        'type' => 'dependencies',
                        'dependencies' => [
                            'PluginB' => ['required' => true],
                        ],
                    ]],
                ],
            ],
            'PluginB' => [
                'assets' => [
                    'dependencies' => [[
                        'type' => 'dependencies',
                        'dependencies' => [
                            'PluginC' => ['required' => true],
                        ],
                    ]],
                ],
            ],
            'PluginC' => [
                'assets' => [
                    'dependencies' => [[
                        'type' => 'dependencies',
                        'dependencies' => [
                            'PluginA' => ['required' => true],
                        ],
                    ]],
                ],
            ],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Circular dependencies detected');

        $this->resolver->buildDependencyTree($availablePlugins, ['PluginA'], []);
    }

    /**
     * Test missing plugin in dependency tree
     */
    public function testBuildDependencyTreeMissingPlugin(): void
    {
        $availablePlugins = [
            'PluginA' => [
                'assets' => [
                    'dependencies' => [[
                        'type' => 'dependencies',
                        'dependencies' => [
                            'PluginB' => ['required' => true],
                        ],
                    ]],
                ],
            ],
        ];

        $result = $this->resolver->buildDependencyTree($availablePlugins, ['PluginA'], []);

        $this->assertIsArray($result);
        $this->assertCount(1, $result);
        $this->assertArrayHasKey('PluginA', $result);
    }
}
