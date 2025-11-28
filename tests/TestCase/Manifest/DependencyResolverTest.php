<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Test\TestCase\Manifest;

use Cake\TestSuite\TestCase;
use Crustum\PluginManifest\Manifest\DependencyResolver;
use InvalidArgumentException;

/**
 * DependencyResolver Test Case
 */
class DependencyResolverTest extends TestCase
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
     * Test dependency order resolution with simple dependencies
     */
    public function testResolveDependencyOrderSimple(): void
    {
        $dependencies = [
            'PluginA' => [
                'dependencies' => [
                    'PluginB' => ['required' => true],
                ],
            ],
            'PluginB' => [
                'dependencies' => [],
            ],
        ];

        $result = $this->resolver->resolveDependencyOrder($dependencies);

        $this->assertEquals(['PluginB', 'PluginA'], $result);
    }

    /**
     * Test dependency order resolution with complex dependencies
     */
    public function testResolveDependencyOrderComplex(): void
    {
        $dependencies = [
            'PluginA' => [
                'dependencies' => [
                    'PluginB' => ['required' => true],
                    'PluginC' => ['required' => true],
                ],
            ],
            'PluginB' => [
                'dependencies' => [
                    'PluginD' => ['required' => true],
                ],
            ],
            'PluginC' => [
                'dependencies' => [
                    'PluginD' => ['required' => true],
                ],
            ],
            'PluginD' => [
                'dependencies' => [],
            ],
        ];

        $result = $this->resolver->resolveDependencyOrder($dependencies);

        // PluginD should be first, then B and C (order may vary), then A
        $this->assertEquals('PluginD', $result[0]);
        $this->assertEquals('PluginA', $result[3]);
        $this->assertContains('PluginB', $result);
        $this->assertContains('PluginC', $result);
    }

    /**
     * Test circular dependency detection
     */
    public function testCircularDependencyDetection(): void
    {
        $dependencies = [
            'PluginA' => [
                'dependencies' => [
                    'PluginB' => ['required' => true],
                ],
            ],
            'PluginB' => [
                'dependencies' => [
                    'PluginC' => ['required' => true],
                ],
            ],
            'PluginC' => [
                'dependencies' => [
                    'PluginA' => ['required' => true],
                ],
            ],
        ];

        $circular = $this->resolver->checkCircularDependencies($dependencies);
        $this->assertNotEmpty($circular);
        $this->assertContains('PluginA', $circular);
    }

    /**
     * Test circular dependency exception
     */
    public function testCircularDependencyException(): void
    {
        $dependencies = [
            'PluginA' => [
                'dependencies' => [
                    'PluginB' => ['required' => true],
                ],
            ],
            'PluginB' => [
                'dependencies' => [
                    'PluginA' => ['required' => true],
                ],
            ],
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Circular dependencies detected');

        $this->resolver->resolveDependencyOrder($dependencies);
    }

    /**
     * Test no circular dependencies
     */
    public function testNoCircularDependencies(): void
    {
        $dependencies = [
            'PluginA' => [
                'dependencies' => [
                    'PluginB' => ['required' => true],
                ],
            ],
            'PluginB' => [
                'dependencies' => [],
            ],
        ];

        $circular = $this->resolver->checkCircularDependencies($dependencies);
        $this->assertEmpty($circular);
    }

    /**
     * Test dependency filtering - required only
     */
    public function testFilterDependenciesRequired(): void
    {
        $dependencies = [
            'PluginA' => [
                'required' => true,
                'tags' => ['config'],
            ],
            'PluginB' => [
                'required' => false,
                'tags' => ['config'],
            ],
            'PluginC' => [
                'required' => true,
                'tags' => ['migrations'],
            ],
        ];

        $filtered = $this->resolver->filterDependencies($dependencies, []);

        $this->assertArrayHasKey('PluginA', $filtered);
        $this->assertArrayHasKey('PluginC', $filtered);
        $this->assertArrayNotHasKey('PluginB', $filtered);
    }

    /**
     * Test dependency filtering - with optional
     */
    public function testFilterDependenciesWithOptional(): void
    {
        $dependencies = [
            'PluginA' => [
                'required' => true,
                'tags' => ['config'],
            ],
            'PluginB' => [
                'required' => false,
                'tags' => ['config'],
            ],
        ];

        $filtered = $this->resolver->filterDependencies($dependencies, [
            'install_optional' => true,
        ]);

        $this->assertArrayHasKey('PluginA', $filtered);
        $this->assertArrayHasKey('PluginB', $filtered);
    }

    /**
     * Test dependency filtering - force all
     */
    public function testFilterDependenciesForceAll(): void
    {
        $dependencies = [
            'PluginA' => [
                'required' => false,
                'tags' => ['config'],
            ],
            'PluginB' => [
                'required' => false,
                'tags' => ['config'],
            ],
        ];

        $filtered = $this->resolver->filterDependencies($dependencies, [
            'force_all' => true,
        ]);

        $this->assertArrayHasKey('PluginA', $filtered);
        $this->assertArrayHasKey('PluginB', $filtered);
    }

    /**
     * Test condition evaluation - file exists
     */
    public function testEvaluateConditionFileExists(): void
    {
        $dependencies = [
            'PluginA' => [
                'required' => false,
                'condition' => 'file_exists',
                'condition_path' => __FILE__, // This file exists
            ],
            'PluginB' => [
                'required' => false,
                'condition' => 'file_exists',
                'condition_path' => '/non/existent/file.php',
            ],
        ];

        $filtered = $this->resolver->filterDependencies($dependencies, [
            'install_optional' => true,
        ]);

        $this->assertArrayHasKey('PluginA', $filtered);
        $this->assertArrayNotHasKey('PluginB', $filtered);
    }

    /**
     * Test condition evaluation - callable
     */
    public function testEvaluateConditionCallable(): void
    {
        $dependencies = [
            'PluginA' => [
                'required' => false,
                'condition' => function () {

                    return true;
                },
            ],
            'PluginB' => [
                'required' => false,
                'condition' => function () {

                    return false;
                },
            ],
        ];

        $filtered = $this->resolver->filterDependencies($dependencies, [
            'install_optional' => true,
        ]);

        $this->assertArrayHasKey('PluginA', $filtered);
        $this->assertArrayNotHasKey('PluginB', $filtered);
    }

    /**
     * Test getting dependency info
     */
    public function testGetDependencyInfo(): void
    {
        $dependencies = [
            'PluginA' => [
                'required' => true,
                'tags' => ['config'],
                'reason' => 'Test reason A',
                'prompt' => 'Install Plugin A?',
            ],
            'PluginB' => [
                'required' => false,
                'tags' => ['config', 'migrations'],
                'reason' => 'Test reason B',
            ],
        ];

        $info = $this->resolver->getDependencyInfo($dependencies);

        $this->assertArrayHasKey('PluginA', $info);
        $this->assertArrayHasKey('PluginB', $info);

        $this->assertTrue($info['PluginA']['required']);
        $this->assertFalse($info['PluginB']['required']);

        $this->assertEquals(['config'], $info['PluginA']['tags']);
        $this->assertEquals(['config', 'migrations'], $info['PluginB']['tags']);

        $this->assertEquals('Test reason A', $info['PluginA']['reason']);
        $this->assertEquals('Test reason B', $info['PluginB']['reason']);

        $this->assertEquals('Install Plugin A?', $info['PluginA']['prompt']);
        $this->assertEquals('Install PluginB plugin assets?', $info['PluginB']['prompt']);
    }

    /**
     * Test empty dependencies
     */
    public function testEmptyDependencies(): void
    {
        $result = $this->resolver->resolveDependencyOrder([]);
        $this->assertEmpty($result);

        $circular = $this->resolver->checkCircularDependencies([]);
        $this->assertEmpty($circular);

        $filtered = $this->resolver->filterDependencies([], []);
        $this->assertEmpty($filtered);

        $info = $this->resolver->getDependencyInfo([]);
        $this->assertEmpty($info);
    }
}
