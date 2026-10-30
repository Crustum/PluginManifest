<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Test\TestCase\Command\Helper;

use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleIoInterface;
use Cake\Console\TestSuite\StubConsoleInput;
use Cake\Console\TestSuite\StubConsoleOutput;
use Cake\TestSuite\TestCase;
use Crustum\PluginManifest\Command\Helper\Installation;
use Crustum\PluginManifest\Command\Helper\OutputFormatter;
use Crustum\PluginManifest\Command\Helper\StarRepo;
use Crustum\PluginManifest\Manifest\BootstrapAppender;
use Crustum\PluginManifest\Manifest\ConfigMerger;
use Crustum\PluginManifest\Manifest\EnvInstaller;
use Crustum\PluginManifest\Manifest\Installer;
use Crustum\PluginManifest\Manifest\InstallResult;
use Crustum\PluginManifest\Manifest\ManifestRegistry;

/**
 * Installation helper test case.
 */
class InstallationTest extends TestCase
{
    protected string $testDir;

    protected string $registryFile;

    protected ManifestRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testDir = TMP . 'tests' . DS . 'installation_' . uniqid() . DS;
        mkdir($this->testDir, 0777, true);

        $this->registryFile = $this->testDir . 'manifest_registry.php';

        $this->registry = new class ($this->registryFile) extends ManifestRegistry {
            protected string $manifestFile = '';

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

        $this->recursiveDelete($this->testDir);
    }

    public function testMigrationRecordsStayStableAcrossRuns(): void
    {
        $sourceDir = $this->testDir . 'source' . DS;
        $destDir = $this->testDir . 'dest' . DS;
        mkdir($sourceDir, 0777, true);

        file_put_contents(
            $sourceDir . '20250101000000_CreateItemsTable.php',
            "<?php\nclass CreateItemsTable extends \\stdClass {}\n",
        );
        file_put_contents(
            $sourceDir . '20250102000000_CreateOrdersTable.php',
            "<?php\nclass CreateOrdersTable extends \\stdClass {}\n",
        );

        $pluginData = [
            'class' => 'TestPlugin',
            'loaded' => false,
            'assets' => [
                'migrations' => [
                    [
                        'type' => 'copy',
                        'source' => $sourceDir,
                        'destination' => $destDir,
                        'options' => [
                            'rename_with_plugin' => true,
                            'plugin_namespace' => 'Test',
                        ],
                        'tag' => 'migrations',
                        'plugin' => 'TestPlugin',
                    ],
                ],
            ],
        ];

        $installation = $this->installation(
            new Installer(new BootstrapAppender(), new ConfigMerger(), new EnvInstaller(), $this->registry),
        );

        $io = new ConsoleIo(new StubConsoleOutput(), new StubConsoleOutput(), new StubConsoleInput([]));

        $this->install($installation, $io, $pluginData);
        $this->assertCount(2, $this->registry->getInstalled('TestPlugin', 'copy', 'migrations'));

        $exitCode = $this->install($installation, $io, $pluginData);
        $this->assertCount(2, $this->registry->getInstalled('TestPlugin', 'copy', 'migrations'));
        $this->assertSame(0, $exitCode);
    }

    public function testSkippedPresentMigrationIsRecorded(): void
    {
        $sourceDir = $this->testDir . 'source' . DS;
        $destDir = $this->testDir . 'dest' . DS;
        mkdir($sourceDir, 0777, true);
        mkdir($destDir, 0777, true);

        file_put_contents(
            $sourceDir . '20250101000000_CreateItemsTable.php',
            "<?php\nclass CreateItemsTable extends \\stdClass {}\n",
        );
        file_put_contents(
            $sourceDir . '20250102000000_CreateOrdersTable.php',
            "<?php\nclass CreateOrdersTable extends \\stdClass {}\n",
        );

        // One migration already present at the destination: skipped, not installed.
        file_put_contents(
            $destDir . '20250101000000_TestCreateItemsTable.php',
            "<?php\nclass TestCreateItemsTable extends \\stdClass {}\n",
        );

        $pluginData = [
            'class' => 'TestPlugin',
            'loaded' => false,
            'assets' => [
                'migrations' => [
                    [
                        'type' => 'copy',
                        'source' => $sourceDir,
                        'destination' => $destDir,
                        'options' => [
                            'rename_with_plugin' => true,
                            'plugin_namespace' => 'Test',
                        ],
                        'tag' => 'migrations',
                        'plugin' => 'TestPlugin',
                    ],
                ],
            ],
        ];

        $installation = $this->installation(
            new Installer(new BootstrapAppender(), new ConfigMerger(), new EnvInstaller(), $this->registry),
        );

        $io = new ConsoleIo(new StubConsoleOutput(), new StubConsoleOutput(), new StubConsoleInput([]));
        $this->install($installation, $io, $pluginData);

        // Both the fresh install and the skipped-but-present file are tracked.
        $this->assertCount(2, $this->registry->getInstalled('TestPlugin', 'copy', 'migrations'));
    }

    /**
     * Run installation without any console output.
     *
     * Prompt helpers (intro/outro) render straight to stdout, bypassing
     * the injected ConsoleIo, so tests use a silent formatter instead.
     *
     * @param \Crustum\PluginManifest\Manifest\Installer $installer Installer
     * @return \Crustum\PluginManifest\Command\Helper\Installation
     */
    protected function installation(Installer $installer): Installation
    {
        $formatter = new class extends OutputFormatter {
            public function displayPluginIntro(ConsoleIoInterface $io, string $pluginName, ?array $tags = null): void
            {
            }

            public function displayPluginOutro(
                ConsoleIoInterface $io,
                string $pluginName,
                int $installed,
                int $skipped,
                int $errors,
            ): void {
            }

            public function displayResult(ConsoleIoInterface $io, InstallResult $result): void
            {
            }

            public function displayBatchSummary(ConsoleIoInterface $io, int $processed, int $successful, int $errors): void
            {
            }

            public function displayDryRunNote(ConsoleIoInterface $io): void
            {
            }
        };

        return new Installation(
            $installer,
            $this->registry,
            $formatter,
            new StarRepo($this->registry),
        );
    }

    /**
     * Invoke installation and return its exit code.
     *
     * @param \Crustum\PluginManifest\Command\Helper\Installation $installation Installation helper
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @param array<string, mixed> $pluginData Plugin data
     * @return int Exit code
     */
    protected function install(Installation $installation, ConsoleIo $io, array $pluginData): int
    {
        return $installation->installPlugin($io, 'TestPlugin', $pluginData, null, ['dry_run' => false]);
    }

    protected function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff((array)scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DS . $file;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
