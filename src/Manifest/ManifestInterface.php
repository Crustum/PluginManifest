<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Manifest;

/**
 * Interface for plugins that want to install assets to the application
 *
 * Plugins implementing this interface can define what assets (config files,
 * migrations, templates, env vars, bootstrap code) should be installable
 * to the application.
 */
interface ManifestInterface
{
    /**
     * Returns array of installable assets with operation types
     *
     * @return array<int, array<string, mixed>> Array of asset definitions
     */
    public static function manifest(): array;
}
