<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Test\TestCase\Command\Helper;

use Cake\TestSuite\TestCase;
use Crustum\PluginManifest\Command\Helper\InstallSelection;

/**
 * Unit tests for InstallSelection branch resolution (Phase A).
 */
class InstallSelectionTest extends TestCase
{
    public function testResolveSelectedPluginsEmptyIsCancel(): void
    {
        $selection = new InstallSelection();

        $this->assertNull($selection->resolveSelectedPlugins([], ['A', 'B']));
    }

    public function testResolveSelectedPluginsAllKeyExpands(): void
    {
        $selection = new InstallSelection();

        $this->assertSame(
            ['A', 'B'],
            $selection->resolveSelectedPlugins([InstallSelection::ALL_PLUGINS], ['A', 'B']),
        );
    }

    public function testResolveSelectedPluginsAllWithExtrasExpands(): void
    {
        $selection = new InstallSelection();

        $this->assertSame(
            ['A', 'B'],
            $selection->resolveSelectedPlugins([InstallSelection::ALL_PLUGINS, 'A'], ['A', 'B']),
        );
    }

    public function testResolveSelectedPluginsSingle(): void
    {
        $selection = new InstallSelection();

        $this->assertSame(
            ['B'],
            $selection->resolveSelectedPlugins(['B'], ['A', 'B']),
        );
    }

    public function testResolveSelectedPluginsMultiple(): void
    {
        $selection = new InstallSelection();

        $this->assertSame(
            ['A', 'B'],
            $selection->resolveSelectedPlugins(['A', 'B'], ['A', 'B', 'C']),
        );
    }

    public function testResolveSelectedTagsEmptyIsCancel(): void
    {
        $selection = new InstallSelection();

        $this->assertFalse($selection->resolveSelectedTags([], ['config', 'migrations']));
    }

    public function testResolveSelectedTagsAllKeyMeansAll(): void
    {
        $selection = new InstallSelection();

        $this->assertNull(
            $selection->resolveSelectedTags([InstallSelection::ALL_TAGS], ['config', 'migrations']),
        );
    }

    public function testResolveSelectedTagsAllConcreteMeansAll(): void
    {
        $selection = new InstallSelection();

        $this->assertNull(
            $selection->resolveSelectedTags(['config', 'migrations'], ['config', 'migrations']),
        );
    }

    public function testResolveSelectedTagsSubset(): void
    {
        $selection = new InstallSelection();

        $this->assertSame(
            ['config'],
            $selection->resolveSelectedTags(['config'], ['config', 'migrations']),
        );
    }
}
