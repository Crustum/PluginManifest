<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Manifest;

/**
 * Trait providing helper methods for plugin manifest definitions
 *
 * This trait provides convenient methods for defining different types of
 * installable assets in plugin manifest() methods.
 */
trait ManifestTrait
{
    /**
     * Get plugin name from class name
     *
     * Extracts plugin name by removing namespace and 'Plugin' suffix.
     *
     * @return string Plugin name
     */
    protected static function getPluginName(): string
    {
        $pluginName = static::class;
        $pluginName = substr($pluginName, strrpos($pluginName, '\\') + 1);
        $pluginName = str_replace('Plugin', '', $pluginName);

        return $pluginName;
    }

    /**
     * Define migrations to install with plugin namespace
     *
     * Migrations are copied with plugin namespace prefix added to prevent
     * class name conflicts. Original timestamps are preserved to maintain
     * inter-plugin dependency order.
     *
     * @param string $source Source directory containing migrations
     * @param string|null $destination Destination directory (defaults to CONFIG/Migrations)
     * @return array<int, array<string, mixed>>
     */
    protected static function manifestMigrations(
        string $source,
        ?string $destination = null,
    ): array {
        $destination = $destination ?? CONFIG . 'Migrations';

        return [[
            'type' => OperationType::COPY,
            'tag' => Tag::MIGRATIONS,
            'source' => $source,
            'destination' => $destination,
            'options' => [
                'plugin_namespace' => static::getPluginName(),
                'rename_with_plugin' => true,
            ],
        ]];
    }

    /**
     * Define config file to install
     *
     * Config files use copy-safe by default (never overwrite existing files)
     * to preserve user customizations and comments.
     *
     * @param string $source Source config file
     * @param string $destination Destination config file path
     * @param bool $canOverwrite Whether file can be overwritten with --force
     * @return array<int, array<string, mixed>>
     */
    protected static function manifestConfig(
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
     * Define environment variables to append to .env
     *
     * Only adds vars that don't already exist in .env file.
     * Parses existing .env and skips variables that are already set.
     *
     * @param array<string, string> $envVars Environment variables as key => value pairs
     * @param string|null $comment Optional comment to add before vars
     * @return array<int, array<string, mixed>>
     */
    protected static function manifestEnvVars(
        array $envVars,
        ?string $comment = null,
    ): array {
        $pluginName = static::getPluginName();

        $comment = $comment ?? '# ' . $pluginName . ' Configuration';

        return [[
            'type' => OperationType::APPEND_ENV,
            'tag' => Tag::ENVS,
            'env_vars' => $envVars,
            'destination' => ROOT . DS . '.env',
            'comment' => $comment,
            'plugin' => $pluginName,
        ]];
    }

    /**
     * Define .env.example file to install
     *
     * Copies .env.example file as plugin-specific example.
     * Never overwrites existing files.
     *
     * @param string $source Source .env.example file
     * @return array<int, array<string, mixed>>
     */
    protected static function manifestEnvExample(
        string $source,
    ): array {
        $pluginName = static::getPluginName();

        $destination = ROOT . DS . '.env.' . strtolower($pluginName) . '.example';

        return [[
            'type' => OperationType::COPY_SAFE,
            'tag' => Tag::ENVS,
            'source' => $source,
            'destination' => $destination,
        ]];
    }

    /**
     * Define webroot assets to install
     *
     * Copies public assets (CSS, JS, images) to application webroot.
     *
     * @param string $source Source webroot directory
     * @param string|null $destination Destination directory (defaults to WWW_ROOT/pluginname)
     * @return array<int, array<string, mixed>>
     */
    protected static function manifestWebroot(
        string $source,
        ?string $destination = null,
    ): array {
        $pluginName = static::getPluginName();
        $destination = $destination ?? WWW_ROOT . strtolower($pluginName);

        return [[
            'type' => OperationType::COPY,
            'tag' => Tag::WEBROOT,
            'source' => $source,
            'destination' => $destination,
        ]];
    }

    /**
     * Define content to append to bootstrap file
     *
     * Appends code to bootstrap files with marker-based duplicate detection.
     * Once appended, marked as completed and won't append again.
     *
     * Supports: bootstrap.php, bootstrap_after.php, plugin_bootstrap_after.php
     *
     * @param string $content Content to append
     * @param string|null $marker Marker comment for duplicate detection
     * @param string $bootstrapFile Bootstrap file name
     * @return array<int, array<string, mixed>>
     */
    protected static function manifestBootstrapAppend(
        string $content,
        ?string $marker = null,
        string $bootstrapFile = 'bootstrap.php',
    ): array {
        $pluginName = static::getPluginName();

        $marker = $marker ?? '// ' . $pluginName . ' Configuration';

        return [[
            'type' => OperationType::APPEND,
            'tag' => Tag::BOOTSTRAP,
            'content' => $content,
            'destination' => CONFIG . $bootstrapFile,
            'marker' => $marker,
            'plugin' => $pluginName,
        ]];
    }

    /**
     * Define content to append to bootstrap_after.php
     *
     * @param string $content Content to append
     * @param string|null $marker Marker comment for duplicate detection
     * @return array<int, array<string, mixed>>
     */
    protected static function manifestBootstrapAfter(
        string $content,
        ?string $marker = null,
    ): array {
        return static::manifestBootstrapAppend($content, $marker, 'bootstrap_after.php');
    }

    /**
     * Define content to append to plugin_bootstrap_after.php
     *
     * @param string $content Content to append
     * @param string|null $marker Marker comment for duplicate detection
     * @return array<int, array<string, mixed>>
     */
    protected static function manifestPluginBootstrapAfter(
        string $content,
        ?string $marker = null,
    ): array {
        return static::manifestBootstrapAppend($content, $marker, 'plugin_bootstrap_after.php');
    }

    /**
     * Define configuration to merge into app config file
     *
     * Merges configuration while preserving user comments and file structure.
     * Inserts new config entries before return statement.
     *
     * @param string $configKey Configuration key
     * @param array<string, mixed> $configValue Configuration value
     * @param string $configFile Config file name (defaults to app_local.php)
     * @return array<int, array<string, mixed>>
     */
    protected static function manifestConfigMerge(
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
            'plugin' => static::getPluginName(),
        ]];
    }

    /**
     * Define plugin dependencies to install
     *
     * Dependencies can be required or optional, with specific tags and user prompts.
     * Supports conditional dependencies based on configuration or user choice.
     *
     * @param array<string, array<string, mixed>> $dependencies Plugin dependencies configuration
     * @return array<int, array<string, mixed>>
     */
    protected static function manifestDependencies(array $dependencies): array
    {
        return [[
            'type' => OperationType::DEPENDENCIES,
            'tag' => Tag::DEPENDENCIES,
            'dependencies' => $dependencies,
            'plugin' => static::getPluginName(),
        ]];
    }

    /**
     * Enable GitHub star prompt for this plugin
     *
     * If not called, the system will attempt to auto-detect the GitHub repo
     * from Packagist using the composer.json package name.
     *
     * @param string|null $vendorSlashRepoName GitHub repository in vendor/repo format (e.g., 'skie/plugin-manifest') or null for auto-detection
     * @param bool $defaultAnswer Default answer for the prompt (defaults to true)
     * @return array<int, array<string, mixed>>
     */
    protected static function manifestStarRepo(?string $vendorSlashRepoName = null, bool $defaultAnswer = true): array
    {
        return [[
            'type' => OperationType::STAR_REPO,
            'tag' => Tag::STAR_REPO,
            'repo' => $vendorSlashRepoName,
            'default_answer' => $defaultAnswer,
            'plugin' => static::getPluginName(),
        ]];
    }
}
