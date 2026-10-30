<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Test\TestCase\Command\Helper;

use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleInput;
use Cake\Console\TestSuite\StubConsoleOutput;
use Cake\TestSuite\TestCase;
use Crustum\PluginManifest\Command\Helper\StarRepo;
use Crustum\PluginManifest\Manifest\ManifestRegistry;

/**
 * Star repo helper test case.
 *
 * Never exercises the interactive prompt itself: the underlying confirm
 * helper does not honor stubbed input and a default "yes" would open a
 * real browser via exec(). Prompt-avoidance paths are tested instead.
 */
class StarRepoTest extends TestCase
{
    protected string $testRegistryFile;

    protected ManifestRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testRegistryFile = TMP . 'test_star_registry_' . uniqid() . '.php';

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

    public function testRecordedPromptIsNeverAskedAgain(): void
    {
        $this->registry->recordStarPromptShown('TestPlugin');

        $starRepo = new StarRepo($this->registry);
        $asset = ['type' => 'star-repo', 'tag' => 'star-repo', 'repo' => 'acme/demo'];

        // Empty input throws if anything tries to read it.
        $out = new StubConsoleOutput();
        $io = new ConsoleIo($out, $out, new StubConsoleInput([]));
        $starRepo->askToStarRepo($io, 'TestPlugin', $asset, []);

        $this->assertTrue($this->registry->hasStarPromptBeenShown('TestPlugin'));
        $this->assertSame([], $out->messages());
    }

    public function testRecordRoundTrip(): void
    {
        $this->assertFalse($this->registry->hasStarPromptBeenShown('TestPlugin'));

        $this->registry->recordStarPromptShown('TestPlugin');

        $this->assertTrue($this->registry->hasStarPromptBeenShown('TestPlugin'));
    }

    public function testDryRunNeverAsksNorRecords(): void
    {
        $starRepo = new StarRepo($this->registry);
        $asset = ['type' => 'star-repo', 'tag' => 'star-repo', 'repo' => 'acme/demo'];

        $out = new StubConsoleOutput();
        $io = new ConsoleIo($out, $out, new StubConsoleInput([]));
        $starRepo->askToStarRepo($io, 'TestPlugin', $asset, ['dry_run' => true]);

        $this->assertFalse($this->registry->hasStarPromptBeenShown('TestPlugin'));
        $this->assertSame([], $out->messages());
    }

    public function testAllDepsNeverAsksNorRecords(): void
    {
        $starRepo = new StarRepo($this->registry);
        $asset = ['type' => 'star-repo', 'tag' => 'star-repo', 'repo' => 'acme/demo'];

        $out = new StubConsoleOutput();
        $io = new ConsoleIo($out, $out, new StubConsoleInput([]));
        $starRepo->askToStarRepo($io, 'TestPlugin', $asset, ['all_deps' => true]);

        $this->assertFalse($this->registry->hasStarPromptBeenShown('TestPlugin'));
        $this->assertSame([], $out->messages());
    }
}
