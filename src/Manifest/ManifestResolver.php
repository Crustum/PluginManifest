<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Manifest;

use Throwable;

/**
 * Resolves manifest operations for a loaded plugin.
 *
 * Prefers the classic contract ({@see ManifestInterface}); when the plugin
 * class does not implement it, falls back to a standalone provider class
 * following the `{PluginNamespace}\Manifest\{Plugin}Manifest` convention
 * (e.g. `Crustum\DebugBar\Manifest\DebugBarManifest` for
 * `Crustum\DebugBar\DebugBarPlugin`). Providers are plain classes with a
 * static `manifest()` method, so plugins stay installable without a hard
 * dependency on this package at load time.
 */
final class ManifestResolver
{
    /**
     * Resolve manifest operations for a plugin class.
     *
     * Returns null when the plugin provides no manifest through either path.
     *
     * @param class-string $pluginClass Loaded plugin class name
     * @return array<int, array<string, mixed>>|null Manifest operations or null
     */
    public static function manifestFor(string $pluginClass): ?array
    {
        if (is_subclass_of($pluginClass, ManifestInterface::class)) {
            try {
                return $pluginClass::manifest();
            } catch (Throwable) {
                return null;
            }
        }

        $provider = self::providerClass($pluginClass);
        if ($provider === null || !method_exists($provider, 'manifest')) {
            return null;
        }

        try {
            $assets = $provider::manifest();
        } catch (Throwable) {
            return null;
        }

        return is_array($assets) ? $assets : null;
    }

    /**
     * Resolve the standalone provider class for a plugin class.
     *
     * Returns null when the plugin class name carries no namespace or the
     * provider class is not loadable.
     *
     * @param class-string $pluginClass Loaded plugin class name
     * @return class-string|null Provider class name or null
     */
    public static function providerClass(string $pluginClass): ?string
    {
        $position = strrpos($pluginClass, '\\');
        if ($position === false) {
            return null;
        }

        $namespace = substr($pluginClass, 0, $position);
        $short = substr($pluginClass, $position + 1);
        if (str_ends_with($short, 'Plugin')) {
            $short = substr($short, 0, -strlen('Plugin'));
        }

        if ($short === '') {
            return null;
        }

        $provider = $namespace . '\\Manifest\\' . $short . 'Manifest';
        if (!class_exists($provider)) {
            return null;
        }

        return $provider;
    }
}
