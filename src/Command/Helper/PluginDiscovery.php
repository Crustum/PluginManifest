<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Command\Helper;

use Cake\Console\ConsoleIo;
use Cake\Core\Plugin;
use Crustum\Inspector\CakePlugin;
use Crustum\Inspector\ProjectScan;
use Crustum\PluginManifest\Manifest\ManifestResolver;
use Exception;
use Throwable;

/**
 * Helper for discovering and organizing plugin assets
 */
class PluginDiscovery
{
    /**
     * Discover plugins that provide a manifest.
     *
     * Covers loaded plugins plus composer-installed `cakephp-plugin`
     * packages (via Inspector, including unloaded ones) and explicit
     * extra paths. Covers both the {@see ManifestInterface} contract and
     * standalone provider classes (see {@see ManifestResolver}).
     *
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @param list<string> $extraPaths Additional plugin directories to scan
     * @param string|null $basePath Project base path for the Inspector scan
     * @return array<string, array<string, mixed>> Plugin data keyed by plugin name
     */
    public function discoverPublishablePlugins(ConsoleIo $io, array $extraPaths = [], ?string $basePath = null): array
    {
        $plugins = [];

        foreach ($this->discoverRaw($extraPaths, $basePath, $io) as $pluginName => $data) {
            try {
                $plugins[$pluginName] = [
                    'class' => $data['class'],
                    'loaded' => $data['loaded'],
                    'path' => $data['path'],
                    'composer' => $data['composer'],
                    'assets' => $this->organizeAssetsByTag($data['manifest'], $pluginName),
                ];
            } catch (Exception $e) {
                $io->warning("Failed to get assets from {$pluginName}: " . $e->getMessage());
            }
        }

        return $plugins;
    }

    /**
     * Discover raw manifests keyed by CakePHP plugin name.
     *
     * Each entry carries `class`, raw `manifest` ops, `loaded` flag,
     * install `path` and composer package name (`composer`, null for
     * loaded plugins without package info). Loaded plugins win on
     * class collisions.
     *
     * @param list<string> $extraPaths Additional plugin directories to scan
     * @param string|null $basePath Project base path for the Inspector scan
     * @param \Cake\Console\ConsoleIo|null $io Console IO for warnings
     * @return array<string, array<string, mixed>>
     */
    public function discoverRaw(array $extraPaths = [], ?string $basePath = null, ?ConsoleIo $io = null): array
    {
        $plugins = [];
        $seenClasses = [];

        foreach (Plugin::loaded() as $pluginName) {
            try {
                $plugin = Plugin::getCollection()->get($pluginName);
            } catch (Exception) {
                continue;
            }

            $pluginClass = $plugin::class;
            $manifest = ManifestResolver::manifestFor($pluginClass);
            if ($manifest === null || $manifest === []) {
                continue;
            }

            $plugins[$pluginName] = [
                'class' => $pluginClass,
                'manifest' => $manifest,
                'loaded' => true,
                'path' => null,
                'composer' => null,
            ];
            $seenClasses[$pluginClass] = $pluginName;
        }

        foreach ($this->inspectorDetails($extraPaths, $basePath, $io) as $detail) {
            $pluginClass = $detail->pluginClass();
            if ($pluginClass === '' || !class_exists($pluginClass)) {
                continue;
            }

            if (isset($seenClasses[$pluginClass])) {
                continue;
            }

            $manifest = ManifestResolver::manifestFor($pluginClass);
            if ($manifest === null || $manifest === []) {
                continue;
            }

            $pluginName = $this->displayName($detail);
            if (isset($plugins[$pluginName])) {
                continue;
            }

            $plugins[$pluginName] = [
                'class' => $pluginClass,
                'manifest' => $manifest,
                'loaded' => false,
                'path' => $detail->path(),
                'composer' => $detail->package(),
            ];
            $seenClasses[$pluginClass] = $pluginName;
        }

        return $plugins;
    }

    /**
     * Derive the display name for an Inspector-discovered plugin.
     *
     * @param \Crustum\Inspector\CakePlugin $detail Inspector plugin detail
     * @return string
     */
    protected function displayName(CakePlugin $detail): string
    {
        $name = $detail->name();
        if (str_contains($name, '/')) {
            return $name;
        }

        $class = $detail->pluginClass();
        $position = strrpos($class, '\\');
        if ($position !== false) {
            return str_replace('\\', '/', substr($class, 0, $position));
        }

        return $name !== '' ? $name : $class;
    }

    /**
     * Resolve Cake plugins via Inspector without loading them.
     *
     * @param list<string> $extraPaths Additional plugin directories to scan
     * @param string|null $basePath Project base path for the Inspector scan
     * @param \Cake\Console\ConsoleIo|null $io Console IO for warnings
     * @return list<\Crustum\Inspector\CakePlugin>
     */
    protected function inspectorDetails(array $extraPaths, ?string $basePath, ?ConsoleIo $io): array
    {
        if (!class_exists(ProjectScan::class)) {
            return [];
        }

        try {
            return ProjectScan::scan($basePath ?? $this->basePath())->php()->cakePluginDetails($extraPaths);
        } catch (Throwable $throwable) {
            if ($io instanceof ConsoleIo) {
                $io->warning('Inspector scan failed: ' . $throwable->getMessage());
            }

            return [];
        }
    }

    /**
     * Host application base path for the Inspector scan.
     *
     * @return string|null Null lets Inspector fall back to the working directory
     */
    protected function basePath(): ?string
    {
        if (defined('ROOT')) {
            /** @var mixed $root */
            $root = constant('ROOT');
            if (is_string($root) && $root !== '' && is_dir($root)) {
                return $root;
            }
        }

        return null;
    }

    /**
     * Normalize a repeatable --path option value to a list of directories.
     *
     * @param mixed $option Option value
     * @return list<string>
     */
    public static function normalizePaths(mixed $option): array
    {
        $paths = [];
        foreach ((array)$option as $path) {
            $path = trim((string)$path);
            if ($path !== '') {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * Organize assets by tag for easier filtering
     *
     * @param array<int, array<string, mixed>> $assets Asset definitions
     * @param string $pluginName Plugin name
     * @return array<string, array<int, array<string, mixed>>> Assets organized by tag
     */
    public function organizeAssetsByTag(array $assets, string $pluginName): array
    {
        $organized = [];

        foreach ($assets as $asset) {
            $tag = $asset['tag'] ?? 'default';
            $organized[$tag] ??= [];

            $asset['plugin'] = $pluginName;
            $organized[$tag][] = $asset;
        }

        return $organized;
    }
}
