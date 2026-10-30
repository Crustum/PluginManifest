<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Manifest;

use Brick\VarExporter\VarExporter;
use Cake\Console\ConsoleIoInterface;
use Cake\Utility\Hash;
use Crustum\Prompts\Console\Helper\ConfirmHelper;
use Throwable;

/**
 * Registers plugins in `config/plugins.php` before manifest installation.
 *
 * Mirrors the `plugin load` core command mechanism: the config file is
 * included, normalized with `Hash::normalize()`, extended with the plugin
 * entry and exported with Brick VarExporter (plain `var_export` fallback).
 * Registration is file-first: only the file content decides, the runtime
 * collection is never consulted (its `get()` would autoload as a side
 * effect). Nothing is ever instantiated — registration is file-only,
 * the running process picks it up on the next run.
 */
class PluginLoader
{
    /**
     * Once-per-run confirmation state (null = not asked yet).
     *
     * @var bool|null
     */
    protected ?bool $confirmed = null;

    /**
     * Constructor.
     *
     * @param string|null $configFile Plugins config file (defaults to CONFIG/plugins.php)
     */
    public function __construct(
        protected ?string $configFile = null,
    ) {
    }

    /**
     * Ensure a plugin is registered for loading.
     *
     * File-first: a plugin already present in `config/plugins.php` is
     * skipped, anything else is registered (regardless of runtime state).
     *
     * @param string $pluginName CakePHP plugin name
     * @param array<string, mixed> $loadOptions Plugin options (hook flags like core `plugin load`)
     * @param array<string, mixed> $options Install options (`all_deps`, `dry_run`)
     * @param \Cake\Console\ConsoleIoInterface|null $io Console IO
     * @return \Crustum\PluginManifest\Manifest\InstallResult
     */
    public function ensureLoaded(
        string $pluginName,
        array $loadOptions,
        array $options,
        ?ConsoleIoInterface $io = null,
    ): InstallResult {
        if ($options['dry_run'] ?? false) {
            if ($io instanceof ConsoleIoInterface) {
                $io->out("  <info>[DRY]</info> Would register <warning>{$pluginName}</warning> in config/plugins.php");
            }

            return new InstallResult(true, $pluginName, $pluginName, 'would-register', 'Dry run');
        }

        if (!$this->confirmOnce($options, $io)) {
            return new InstallResult(false, $pluginName, $pluginName, 'cancelled', 'Plugin loading declined');
        }

        return $this->register($pluginName, $loadOptions, $io);
    }

    /**
     * Ask once per run whether plugins should be registered.
     *
     * Non-interactive runs (`all_deps`) proceed without asking.
     *
     * @param array<string, mixed> $options Install options
     * @param \Cake\Console\ConsoleIoInterface|null $io Console IO
     * @return bool
     */
    protected function confirmOnce(array $options, ?ConsoleIoInterface $io): bool
    {
        if ($this->confirmed !== null) {
            return $this->confirmed;
        }

        if (!empty($options['all_deps'])) {
            return $this->confirmed = true;
        }

        if (!$io instanceof ConsoleIoInterface) {
            return $this->confirmed = true;
        }

        try {
            $confirm = $io->helper('Crustum/Prompts.Confirm');
            assert($confirm instanceof ConfirmHelper);

            return $this->confirmed = $confirm->run([
                'label' => 'Register plugins in config/plugins.php as they install?',
                'default' => true,
            ]);
        } catch (Throwable) {
            return $this->confirmed = true;
        }
    }

    /**
     * Write the plugin entry to the plugins config file.
     *
     * @param string $pluginName CakePHP plugin name
     * @param array<string, mixed> $loadOptions Plugin options
     * @param \Cake\Console\ConsoleIoInterface|null $io Console IO
     * @return \Crustum\PluginManifest\Manifest\InstallResult
     */
    protected function register(string $pluginName, array $loadOptions, ?ConsoleIoInterface $io): InstallResult
    {
        $configFile = $this->configFile ?? CONFIG . 'plugins.php';
        $created = !is_file($configFile);

        // phpcs:ignore
        $config = @include $configFile;
        if (!is_array($config)) {
            $config = [];
        } else {
            $config = Hash::normalize($config);
        }

        if (array_key_exists($pluginName, $config)) {
            return new InstallResult(true, $pluginName, $configFile, 'skipped', 'Already registered');
        }

        $config[$pluginName] = $loadOptions;

        if (class_exists(VarExporter::class)) {
            $array = VarExporter::export($config, VarExporter::TRAILING_COMMA_IN_ARRAY);
        } else {
            $array = var_export($config, true);
        }

        $contents = '<?php' . "\n\n" . 'return ' . $array . ';' . "\n";
        if (file_put_contents($configFile, $contents) === false) {
            if ($io instanceof ConsoleIoInterface) {
                $io->error("Failed to update `{$configFile}`");
            }

            return new InstallResult(false, $pluginName, $configFile, 'error', "Failed to update `{$configFile}`");
        }

        if ($io instanceof ConsoleIoInterface) {
            $io->out("  <success>[✓]</success> Registered <warning>{$pluginName}</warning> in config/plugins.php");
            if ($created) {
                $io->warning("Created `{$configFile}` — make sure your Application loads it.");
            }
        }

        return new InstallResult(true, $pluginName, $configFile, 'installed', "Registered {$pluginName}");
    }
}
