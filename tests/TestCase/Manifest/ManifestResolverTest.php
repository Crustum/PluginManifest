<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Test\TestCase\Manifest;

use Cake\TestSuite\TestCase;
use Crustum\PluginManifest\Manifest\ManifestResolver;
use Crustum\PluginManifest\Test\TestPlugin\Manifest\PlainManifest;
use Crustum\PluginManifest\Test\TestPlugin\PlainLoadPlugin;
use Crustum\PluginManifest\Test\TestPlugin\PlainPlugin;
use Crustum\PluginManifest\Test\TestPlugin\TestPlugin;
use stdClass;

class ManifestResolverTest extends TestCase
{
    public function testResolvesInterfaceContract(): void
    {
        $assets = ManifestResolver::manifestFor(TestPlugin::class);

        $this->assertNotEmpty($assets);
        $this->assertSame(TestPlugin::manifest(), $assets);
    }

    public function testResolvesProviderConvention(): void
    {
        $this->assertSame(
            PlainManifest::class,
            ManifestResolver::providerClass(PlainPlugin::class),
        );

        $assets = ManifestResolver::manifestFor(PlainPlugin::class);

        $this->assertNotEmpty($assets);
        $this->assertSame('/tmp/plain.php', $assets[0]['source']);
    }

    public function testReturnsNullWithoutManifest(): void
    {
        $this->assertNull(ManifestResolver::manifestFor(stdClass::class));
        $this->assertNull(ManifestResolver::providerClass('NoNamespace'));
    }

    public function testLoadOptionsFromProviderProps(): void
    {
        $this->assertSame(
            ['bootstrap' => true, 'routes' => false, 'onlyDebug' => true],
            ManifestResolver::loadOptions(PlainLoadPlugin::class),
        );
    }

    public function testLoadOptionsEmptyWithoutProviderProps(): void
    {
        $this->assertSame([], ManifestResolver::loadOptions(PlainPlugin::class));
    }

    public function testLoadOptionsEmptyWithoutProvider(): void
    {
        $this->assertSame([], ManifestResolver::loadOptions(TestPlugin::class));
    }
}
