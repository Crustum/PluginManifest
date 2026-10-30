<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Test\TestCase\Manifest;

use Cake\Console\ConsoleIo;
use Cake\Console\TestSuite\StubConsoleInput;
use Cake\Console\TestSuite\StubConsoleOutput;
use Cake\TestSuite\TestCase;
use Crustum\PluginManifest\Manifest\BootstrapAppender;
use Crustum\PluginManifest\Manifest\ConfigMerger;
use Crustum\PluginManifest\Manifest\DependencyInstaller;
use Crustum\PluginManifest\Manifest\DependencyResolver;
use Crustum\PluginManifest\Manifest\EnvInstaller;
use Crustum\PluginManifest\Manifest\Installer;
use Crustum\PluginManifest\Manifest\ManifestRegistry;
use ReflectionMethod;

/**
 * Dependency installer prompting test case.
 */
class DependencyInstallerTest extends TestCase
{
    protected DependencyInstaller $installer;

    protected function setUp(): void
    {
        parent::setUp();

        $registry = new ManifestRegistry();
        $installer = new Installer(
            new BootstrapAppender(),
            new ConfigMerger(),
            new EnvInstaller(),
            $registry,
        );
        $this->installer = new DependencyInstaller(new DependencyResolver(), $installer, $registry);
    }

    public function testRequiredDependenciesInstallWithoutFlags(): void
    {
        $info = [
            'Crustum/Mcp' => [
                'required' => true,
                'tags' => ['config'],
                'reason' => 'Required runtime',
                'prompt' => 'Install Mcp?',
                'condition' => null,
                'condition_met' => true,
            ],
        ];
        $available = ['Crustum/Mcp' => ['class' => 'Crustum\Mcp\McpPlugin', 'assets' => []]];

        // Empty input throws if anything tries to prompt: required must not prompt.
        $io = $this->io([]);

        $toInstall = $this->promptForDependencies($info, $available, [], $io);

        $this->assertArrayHasKey('Crustum/Mcp', $toInstall);
    }

    public function testOptionalDependenciesAreNotInstalledWithoutConfirmation(): void
    {
        $info = [
            'Crustum/Queue' => [
                'required' => false,
                'tags' => ['config'],
                'reason' => 'Optional queue',
                'prompt' => 'Install Queue?',
                'condition' => null,
                'condition_met' => true,
            ],
        ];
        $available = ['Crustum/Queue' => ['class' => 'Crustum\Queue\QueuePlugin', 'assets' => []]];

        // Empty input: the prompt helper falls back to its default (no),
        // so nothing is installed and nothing throws in non-interactive runs.
        $toInstall = $this->promptForDependencies($info, $available, [], $this->io([]));

        $this->assertSame([], $toInstall);
    }

    public function testNoDependenciesFlagSkipsAll(): void
    {
        $info = [
            'Crustum/Mcp' => [
                'required' => true,
                'tags' => ['config'],
                'reason' => 'Required runtime',
                'prompt' => 'Install Mcp?',
                'condition' => null,
                'condition_met' => true,
            ],
        ];
        $available = ['Crustum/Mcp' => ['class' => 'Crustum\Mcp\McpPlugin', 'assets' => []]];

        $io = $this->io([]);

        $this->assertSame([], $this->promptForDependencies($info, $available, ['no_dependencies' => true], $io));
    }

    public function testMissingRequiredDependencyAborts(): void
    {
        $asset = [
            'type' => 'dependencies',
            'tag' => 'dependencies',
            'plugin' => 'Crustum/Ai',
            'dependencies' => [
                'Crustum/Missing' => [
                    'required' => true,
                    'reason' => 'Missing hard requirement',
                ],
            ],
        ];

        $result = $this->installer->installDependencies($asset, [], $this->io([]));

        $this->assertFalse($result->success);
        $this->assertSame('error', $result->status);
    }

    /**
     * Invoke the protected dependency prompter.
     *
     * @param array<string, array<string, mixed>> $info Dependency info
     * @param array<string, array<string, mixed>> $available Available plugins
     * @param array<string, mixed> $options Install options
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @return array<string, array<string, mixed>>
     */
    protected function promptForDependencies(
        array $info,
        array $available,
        array $options,
        ConsoleIo $io,
    ): array {
        $method = new ReflectionMethod(DependencyInstaller::class, 'promptForDependencies');

        return $method->invoke($this->installer, $info, $available, $options, $io);
    }

    /**
     * Console IO with stubbed input.
     *
     * @param list<string> $replies Canned replies
     * @return \Cake\Console\ConsoleIo
     */
    protected function io(array $replies): ConsoleIo
    {
        $out = new StubConsoleOutput();

        return new ConsoleIo($out, $out, new StubConsoleInput($replies));
    }
}
