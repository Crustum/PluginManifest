<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Test\TestCase\Manifest;

use Cake\TestSuite\TestCase;
use Crustum\PluginManifest\Manifest\ManifestOps;
use Crustum\PluginManifest\Manifest\OperationType;
use Crustum\PluginManifest\Manifest\Tag;
use Crustum\PluginManifest\Test\TestPlugin\TestPlugin;
use ReflectionMethod;

class ManifestOpsTest extends TestCase
{
    public function testPluginNameStripsNamespaceAndSuffix(): void
    {
        $this->assertSame('Test', ManifestOps::pluginName(TestPlugin::class));
        $this->assertSame('DebugBar', ManifestOps::pluginName('Crustum\\DebugBar\\DebugBarPlugin'));
    }

    public function testManifestConfigBuildsCopySafeOp(): void
    {
        $ops = ManifestOps::manifestConfig('Test', '/src/a.php', '/dst/a.php');

        $this->assertSame(OperationType::COPY_SAFE, $ops[0]['type']);
        $this->assertSame(Tag::CONFIG, $ops[0]['tag']);
        $this->assertSame('/src/a.php', $ops[0]['source']);
        $this->assertSame('/dst/a.php', $ops[0]['destination']);
    }

    public function testManifestConfigAllowsOverwrite(): void
    {
        $ops = ManifestOps::manifestConfig('Test', '/src/a.php', '/dst/a.php', true);

        $this->assertSame(OperationType::COPY, $ops[0]['type']);
    }

    public function testManifestBootstrapAppendDefaultsMarker(): void
    {
        $ops = ManifestOps::manifestBootstrapAppend('Test', 'echo 1;');

        $this->assertSame(OperationType::APPEND, $ops[0]['type']);
        $this->assertSame('// Test Configuration', $ops[0]['marker']);
        $this->assertSame('Test', $ops[0]['plugin']);
    }

    public function testManifestDependenciesCarriesPlugin(): void
    {
        $ops = ManifestOps::manifestDependencies('Test', ['Foo/Bar' => ['required' => true]]);

        $this->assertSame(OperationType::DEPENDENCIES, $ops[0]['type']);
        $this->assertSame('Test', $ops[0]['plugin']);
        $this->assertTrue($ops[0]['dependencies']['Foo/Bar']['required']);
    }

    public function testTraitDelegatesToOps(): void
    {
        $method = new ReflectionMethod(TestPlugin::class, 'manifestConfig');
        $this->assertSame(
            ManifestOps::manifestConfig('Test', '/src/a.php', '/dst/a.php'),
            $method->invoke(null, '/src/a.php', '/dst/a.php'),
        );
    }
}
