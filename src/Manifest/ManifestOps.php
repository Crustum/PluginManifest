<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Manifest;

/**
 * Static builders for plugin manifest operations.
 *
 * Dependency-free entry point for manifest definitions: unlike
 * {@see ManifestTrait}, this class requires no trait injection, so manifest
 * providers work without a hard dependency on this package at load time.
 * All references to package value objects stay inside method bodies, which
 * only execute when the installer (and therefore this package) is present.
 */
final class ManifestOps
{
    /**
     * Get plugin name from class name.
     *
     * Extracts plugin name by removing namespace and 'Plugin' suffix.
     *
     * @param class-string $class Plugin or provider class name
     * @return string Plugin name
     */
    public static function pluginName(string $class): string
    {
        $pluginName = substr($class, strrpos($class, '\\') + 1);

        return str_replace('Plugin', '', $pluginName);
    }

    /**
     * Define migrations to install with plugin namespace.
     *
     * Migrations are copied with plugin namespace prefix added to prevent
     * class name conflicts. Original timestamps are preserved to maintain
     * inter-plugin dependency order.
     *
     * @param string $plugin Plugin name for migration namespacing
     * @param string $source Source directory containing migrations
     * @param string|null $destination Destination directory (defaults to CONFIG/Migrations)
     * @return array<int, array<string, mixed>>
     */
    public static function manifestMigrations(
        string $plugin,
        string $source,
        ?string $destination = null,
    ): array {
        $destination ??= CONFIG . 'Migrations';

        return [[
            'type' => OperationType::COPY,
            'tag' => Tag::MIGRATIONS,
            'source' => $source,
            'destination' => $destination,
            'options' => [
                'plugin_namespace' => $plugin,
                'rename_with_plugin' => true,
            ],
        ]];
    }

    /**
     * Define config file to install.
     *
     * Config files use copy-safe by default (never overwrite existing files)
     * to preserve user customizations and comments.
     *
     * @param string $plugin Plugin name
     * @param string $source Source config file
     * @param string $destination Destination config file path
     * @param bool $canOverwrite Whether file can be overwritten with --force
     * @return array<int, array<string, mixed>>
     */
    public static function manifestConfig(
        string $plugin,
        string $source,
        string $destination,
        bool $canOverwrite = false,
    ): array {
        return [[
            'type' => $canOverwrite ? OperationType::COPY : OperationType::COPY_SAFE,
            'tag' => Tag::CONFIG,
            'source' => $source,
            'destination' => $destination,
        ]];
    }

    /**
     * Define environment variables to append to .env.
     *
     * Only adds vars that don't already exist in .env file.
     * Parses existing .env and skips variables that are already set.
     *
     * @param string $plugin Plugin name
     * @param array<string, string> $envVars Environment variables as key => value pairs
     * @param string|null $comment Optional comment to add before vars
     * @return array<int, array<string, mixed>>
     */
    public static function manifestEnvVars(
        string $plugin,
        array $envVars,
        ?string $comment = null,
    ): array {
        $comment ??= '# ' . $plugin . ' Configuration';

        return [[
            'type' => OperationType::APPEND_ENV,
            'tag' => Tag::ENVS,
            'env_vars' => $envVars,
            'destination' => ROOT . DS . '.env',
            'comment' => $comment,
            'plugin' => $plugin,
        ]];
    }

    /**
     * Define .env.example file to install.
     *
     * Copies .env.example file as plugin-specific example.
     * Never overwrites existing files.
     *
     * @param string $plugin Plugin name
     * @param string $source Source .env.example file
     * @return array<int, array<string, mixed>>
     */
    public static function manifestEnvExample(
        string $plugin,
        string $source,
    ): array {
        $destination = ROOT . DS . '.env.' . strtolower($plugin) . '.example';

        return [[
            'type' => OperationType::COPY_SAFE,
            'tag' => Tag::ENVS,
            'source' => $source,
            'destination' => $destination,
        ]];
    }

    /**
     * Define webroot assets to install.
     *
     * Copies public assets (CSS, JS, images) to application webroot.
     *
     * @param string $plugin Plugin name
     * @param string $source Source webroot directory
     * @param string|null $destination Destination directory (defaults to WWW_ROOT/pluginname)
     * @return array<int, array<string, mixed>>
     */
    public static function manifestWebroot(
        string $plugin,
        string $source,
        ?string $destination = null,
    ): array {
        $destination ??= WWW_ROOT . strtolower($plugin);

        return [[
            'type' => OperationType::COPY,
            'tag' => Tag::WEBROOT,
            'source' => $source,
            'destination' => $destination,
        ]];
    }

    /**
     * Define content to append to bootstrap file.
     *
     * Appends code to bootstrap files with marker-based duplicate detection.
     * Once appended, marked as completed and won't append again.
     *
     * Supports: bootstrap.php, bootstrap_after.php, plugin_bootstrap_after.php
     *
     * @param string $plugin Plugin name
     * @param string $content Content to append
     * @param string|null $marker Marker comment for duplicate detection
     * @param string $bootstrapFile Bootstrap file name
     * @return array<int, array<string, mixed>>
     */
    public static function manifestBootstrapAppend(
        string $plugin,
        string $content,
        ?string $marker = null,
        string $bootstrapFile = 'bootstrap.php',
    ): array {
        $marker ??= '// ' . $plugin . ' Configuration';

        return [[
            'type' => OperationType::APPEND,
            'tag' => Tag::BOOTSTRAP,
            'content' => $content,
            'destination' => CONFIG . $bootstrapFile,
            'marker' => $marker,
            'plugin' => $plugin,
        ]];
    }

    /**
     * Define content to append to bootstrap_after.php.
     *
     * @param string $plugin Plugin name
     * @param string $content Content to append
     * @param string|null $marker Marker comment for duplicate detection
     * @return array<int, array<string, mixed>>
     */
    public static function manifestBootstrapAfter(
        string $plugin,
        string $content,
        ?string $marker = null,
    ): array {
        return self::manifestBootstrapAppend($plugin, $content, $marker, 'bootstrap_after.php');
    }

    /**
     * Define content to append to plugin_bootstrap_after.php.
     *
     * @param string $plugin Plugin name
     * @param string $content Content to append
     * @param string|null $marker Marker comment for duplicate detection
     * @return array<int, array<string, mixed>>
     */
    public static function manifestPluginBootstrapAfter(
        string $plugin,
        string $content,
        ?string $marker = null,
    ): array {
        return self::manifestBootstrapAppend($plugin, $content, $marker, 'plugin_bootstrap_after.php');
    }

    /**
     * Define configuration to merge into app config file.
     *
     * Merges configuration while preserving user comments and file structure.
     * Inserts new config entries before return statement.
     *
     * @param string $plugin Plugin name
     * @param string $configKey Configuration key
     * @param array<string, mixed> $configValue Configuration value
     * @param string $configFile Config file name (defaults to app_local.php)
     * @return array<int, array<string, mixed>>
     */
    public static function manifestConfigMerge(
        string $plugin,
        string $configKey,
        array $configValue,
        string $configFile = 'app_local.php',
    ): array {
        return [[
            'type' => OperationType::MERGE,
            'tag' => Tag::CONFIG,
            'key' => $configKey,
            'value' => $configValue,
            'destination' => CONFIG . $configFile,
            'plugin' => $plugin,
        ]];
    }

    /**
     * Define plugin dependencies to install.
     *
     * Dependencies can be required or optional, with specific tags and user prompts.
     * Supports conditional dependencies based on configuration or user choice.
     *
     * @param string $plugin Plugin name
     * @param array<string, array<string, mixed>> $dependencies Plugin dependencies configuration
     * @return array<int, array<string, mixed>>
     */
    public static function manifestDependencies(
        string $plugin,
        array $dependencies,
    ): array {
        return [[
            'type' => OperationType::DEPENDENCIES,
            'tag' => Tag::DEPENDENCIES,
            'dependencies' => $dependencies,
            'plugin' => $plugin,
        ]];
    }

    /**
     * Enable GitHub star prompt for this plugin.
     *
     * If not called, the system will attempt to auto-detect the GitHub repo
     * from Packagist using the composer.json package name.
     *
     * @param string $plugin Plugin name
     * @param string|null $vendorSlashRepoName GitHub repository in vendor/repo format (e.g., 'skie/plugin-manifest') or null for auto-detection
     * @param bool $defaultAnswer Default answer for the prompt (defaults to true)
     * @return array<int, array<string, mixed>>
     */
    public static function manifestStarRepo(
        string $plugin,
        ?string $vendorSlashRepoName = null,
        bool $defaultAnswer = true,
    ): array {
        return [[
            'type' => OperationType::STAR_REPO,
            'tag' => Tag::STAR_REPO,
            'repo' => $vendorSlashRepoName,
            'default_answer' => $defaultAnswer,
            'plugin' => $plugin,
        ]];
    }
}
