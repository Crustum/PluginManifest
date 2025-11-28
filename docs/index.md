# CakePHP PluginManifest Plugin

Plugin asset installation and publishing system for CakePHP 5.x applications.

## Table of Contents

- [Introduction](#introduction)
- [Installation](#installation)
- [Basic Usage](#basic-usage)
  - [Implementing ManifestInterface](#implementing-manifestinterface)
  - [Using ManifestTrait Helpers](#using-manifesttrait-helpers)
  - [Installing Assets](#installing-assets)
- [Operation Types](#operation-types)
  - [Copy Operation](#copy-operation)
  - [Copy-Safe Operation](#copy-safe-operation)
  - [Append Operation](#append-operation)
  - [Append-Env Operation](#append-env-operation)
  - [Merge Operation](#merge-operation)
  - [Webroot Operation](#webroot-operation)
  - [Env Example Operation](#env-example-operation)
  - [Bootstrap After Operation](#bootstrap-after-operation)
  - [Plugin Bootstrap After Operation](#plugin-bootstrap-after-operation)
- [Migration Handling](#migration-handling)
- [Plugin Dependencies](#plugin-dependencies)
  - [Declaring Dependencies](#declaring-dependencies)
  - [Dependency Types](#dependency-types)
  - [Installing with Dependencies](#installing-with-dependencies)
- [Command Reference](#command-reference)
- [Tag System](#tag-system)
- [Registry and Tracking](#registry-and-tracking)
- [Advanced Examples](#advanced-examples)
- [Testing](#testing)

<a name="introduction"></a>
## Introduction

The PluginManifest plugin provides a standardized mechanism for CakePHP plugins to distribute optional assets to applications using them. It enables plugin developers to publish configuration files, migrations, templates, environment variables, and bootstrap code.

The system uses interface-based registration for simple discovery and provides convenient helper trait methods for common asset types. Configuration merging preserves user comments and formatting, while migrations automatically receive plugin namespaces to prevent class name conflicts. Smart detection prevents duplicate installations, and interactive prompts guide users through installation choices. The dry run mode allows you to preview all changes before applying them.

<a name="installation"></a>
## Installation

1. Install the plugin via Composer:
```bash
composer require skie/plugin-manifest
```

2. Load the plugin in your `config/plugins.php`:
```php
return [
    'PluginManifest' => [],
];
```

Or in your `Application.php`:
```php
public function bootstrap(): void
{
    parent::bootstrap();

    $this->addPlugin('PluginManifest');
}
```

<a name="basic-usage"></a>
## Basic Usage

<a name="implementing-manifestinterface"></a>
### Implementing ManifestInterface

To make your plugin publishable, implement the `ManifestInterface` in your plugin class:

```php
<?php
namespace YourPlugin;

use Cake\Core\BasePlugin;
use Crustum\PluginManifest\Manifest\ManifestInterface;
use Crustum\PluginManifest\Manifest\ManifestTrait;
use Crustum\PluginManifest\Manifest\Tag;

class YourPlugin extends BasePlugin implements ManifestInterface
{
    use ManifestTrait;

    public static function manifest(): array
    {
        $pluginPath = dirname(__DIR__) . DS;

        return [
            static::manifestConfig(
                $pluginPath . 'config' . DS . 'your_plugin.php',
                CONFIG . 'your_plugin.php'
            ),
        ];
    }
}
```

<a name="using-manifesttrait-helpers"></a>
### Using ManifestTrait Helpers

The `ManifestTrait` provides convenient helper methods for common asset types:

#### Config Files
```php
static::manifestConfig(
    $source,
    $destination,
    $options,
    $tag
)
```

#### Migrations
```php
static::manifestMigrations(
    $sourceDir,
    $destinationDir,
    $options,
    $tag,
    $pluginNamespace
)
```

#### Bootstrap Code
```php
static::manifestBootstrapAppend(
    $content,
    $bootstrapFile,
    $options,
    $tag,
    $marker
)
```

#### Environment Variables
```php
static::manifestEnvVars(
    $envVars,
    $envFile,
    $options,
    $tag,
    $comment
)
```

#### Config Merge
```php
static::manifestConfigMerge(
    $key,
    $value,
    $configFile,
    $options,
    $tag
)
```

<a name="installing-assets"></a>
### Installing Assets

Users can install your plugin's assets using the command:

```bash
# Interactive mode
bin/cake manifest install

# Install all from specific plugin
bin/cake manifest install --plugin YourPlugin

# Install specific tag
bin/cake manifest install --plugin YourPlugin --tag config

# Install all from all plugins
bin/cake manifest install --all

# Dry run (preview only)
bin/cake manifest install --plugin YourPlugin --dry-run

# Force overwrite
bin/cake manifest install --plugin YourPlugin --force
```

<a name="operation-types"></a>
## Operation Types

<a name="copy-operation"></a>
### Copy Operation

Standard file or directory copy. Respects `--force` flag.

```php
[
    'type' => 'copy',
    'source' => '/path/to/source',
    'destination' => '/path/to/destination',
    'tag' => 'config',
]
```

The copy operation will skip if the destination already exists unless you specify the `--force` flag. It can copy individual files or entire directories recursively, and can be re-installed later using the `--force` option.

<a name="copy-safe-operation"></a>
### Copy-Safe Operation

Never overwrites existing files, even with `--force`.

```php
[
    'type' => 'copy-safe',
    'source' => '/path/to/source.php',
    'destination' => '/path/to/destination.php',
    'tag' => 'config',
]
```

This operation is ideal for `.env.example` files, default configuration templates, and any files that users should customize without risk of being overwritten during updates.

<a name="append-operation"></a>
### Append Operation

Appends code to bootstrap files with duplicate prevention.

```php
[
    'type' => 'append',
    'destination' => CONFIG . 'bootstrap.php',
    'content' => "Plugin::load('YourPlugin', ['bootstrap' => true]);",
    'marker' => '// YourPlugin marker',
    'tag' => 'bootstrap',
]
```

The append operation uses marker-based and content-based duplicate detection to ensure code is never added twice. It supports appending to `bootstrap.php`, `bootstrap_after.php`, and `plugin_bootstrap_after.php`. Once installed, the operation won't re-append the same content as it's tracked in the registry.

<a name="append-env-operation"></a>
### Append-Env Operation

Appends environment variables to `.env` files.

```php
[
    'type' => 'append-env',
    'destination' => ROOT . DS . '.env',
    'env_vars' => [
        'YOUR_PLUGIN_API_KEY' => 'your-key-here',
        'YOUR_PLUGIN_ENABLED' => 'true',
    ],
    'comment' => '# YourPlugin Configuration',
    'tag' => 'envs',
]
```

The operation intelligently skips variables that already exist in the file and can be re-run safely to add new variables. It adds a comment section before the variables for organization and will create the `.env` file if it doesn't exist.

<a name="merge-operation"></a>
### Merge Operation

Merges configuration into existing config files while **preserving comments**.

```php
[
    'type' => 'merge',
    'destination' => CONFIG . 'app_local.php',
    'key' => 'YourPlugin',
    'value' => [
        'enabled' => true,
        'api' => [
            'endpoint' => 'https://api.example.com',
            'timeout' => 30,
        ],
    ],
    'tag' => 'config',
]
```

This operation preserves all comments in the original file and skips merging if the key already exists. It supports deeply nested arrays and automatically converts values to short array syntax for consistency. Once installed, the merge won't be repeated as it's tracked in the registry.

**Example output:**
```php
<?php

// Your important comments are preserved
return [
    // Original config with comments
    'existing' => 'value',

    // New config is inserted before closing bracket
    'YourPlugin' => [
        'enabled' => true,
        'api' => [
            'endpoint' => 'https://api.example.com',
            'timeout' => 30,
        ],
    ],
];
```

<a name="migration-handling"></a>
## Migration Handling

Migrations are handled specially to prevent class name conflicts while maintaining dependency order:

**Original migration:**
```
20250101000000_CreateUsersTable.php
class CreateUsersTable extends AbstractMigration
```

**Installed migration:**
```
20250101000000_YourPluginCreateUsersTable.php
class YourPluginCreateUsersTable extends AbstractMigration
```

Migration timestamps are preserved to maintain inter-plugin dependency order. The plugin namespace is automatically added to class names to prevent conflicts. The command can be re-run later to add new migrations without affecting existing ones.

<a name="plugin-dependencies"></a>
## Plugin Dependencies

The manifest system allows plugins to declare dependencies on other plugins. When installing a plugin with dependencies, the system automatically discovers and installs the required dependent plugins in the correct order.

<a name="declaring-dependencies"></a>
### Declaring Dependencies

Dependencies are declared using the `manifestDependencies()` helper method in your plugin's manifest. Each dependency specifies the plugin name, whether it's required or optional, which asset tags to install, and a reason explaining why the dependency is needed.

```php
<?php
namespace YourPlugin;

use Cake\Core\BasePlugin;
use Crustum\PluginManifest\Manifest\ManifestInterface;
use Crustum\PluginManifest\Manifest\ManifestTrait;
use Crustum\PluginManifest\Manifest\Tag;

class YourPlugin extends BasePlugin implements ManifestInterface
{
    use ManifestTrait;

    public static function manifest(): array
    {
        $pluginPath = dirname(__DIR__) . DS;

        return array_merge(
            static::manifestConfig(
                $pluginPath . 'config' . DS . 'your_plugin.php',
                CONFIG . 'your_plugin.php'
            ),

            static::manifestDependencies([
                'CorePlugin' => [
                    'required' => true,
                    'tags' => [Tag::CONFIG, Tag::MIGRATIONS],
                    'reason' => 'Provides essential core functionality for all operations',
                ],
                'CachePlugin' => [
                    'required' => false,
                    'tags' => [Tag::CONFIG],
                    'prompt' => 'Install caching system for performance optimization?',
                    'reason' => 'Enables response caching and reduces database queries',
                ],
            ])
        );
    }
}
```

The dependency declaration creates a clear relationship between your plugin and its dependencies. Required dependencies must be installed before your plugin can function properly. Optional dependencies enhance functionality but are not essential for basic operation.

<a name="dependency-types"></a>
### Dependency Types

**Required Dependencies** must be installed for the plugin to function correctly. The system will not allow installation to proceed if a required dependency is missing or fails to install. The installation process automatically handles required dependencies without prompting the user.

```php
'CorePlugin' => [
    'required' => true,
    'tags' => [Tag::CONFIG, Tag::MIGRATIONS],
    'reason' => 'Required for database operations and core services',
]
```

**Optional Dependencies** enhance plugin functionality but are not essential. During installation, the system prompts users to decide whether to install each optional dependency. Users can choose to install them immediately, skip them, or install them later using the same command.

```php
'NotificationPlugin' => [
    'required' => false,
    'tags' => [Tag::CONFIG],
    'prompt' => 'Install notification system for alerts and messages?',
    'reason' => 'Enables email and SMS notifications for important events',
]
```

**Conditional Dependencies** are automatically evaluated based on system state. The condition can check for file existence, configuration values, or use custom callable functions to determine if the dependency should be offered for installation.

**File Existence Condition:**
```php
'SearchPlugin' => [
    'required' => false,
    'condition' => 'file_exists',
    'condition_path' => CONFIG . 'search.php',
    'tags' => [Tag::CONFIG, Tag::MIGRATIONS],
    'prompt' => 'Search configuration detected. Install search plugin?',
    'reason' => 'Provides full-text search capabilities when search is configured',
]
```

**Configuration Condition:**
```php
'ElasticsearchPlugin' => [
    'required' => false,
    'condition' => 'config_exists',
    'condition_key' => 'Elasticsearch.enabled',
    'tags' => [Tag::CONFIG],
    'reason' => 'Required when Elasticsearch is enabled in configuration',
]
```

**Custom Callable Condition:**
```php
'AdvancedFeatures' => [
    'required' => false,
    'condition' => function() {
        return Configure::read('App.environment') === 'production';
    },
    'tags' => [Tag::CONFIG],
    'reason' => 'Production-specific optimizations and features',
]
```

**Tag Selection** allows you to specify which asset groups should be installed from each dependency. You can install only configuration files, only migrations, or any combination of tags. If no tags are specified, all assets from the dependency will be installed.

```php
'DatabasePlugin' => [
    'required' => true,
    'tags' => [Tag::CONFIG, Tag::MIGRATIONS],
    'reason' => 'Database schema and connection management',
]
```

<a name="installing-with-dependencies"></a>
### Installing with Dependencies

The installation command provides several options for handling dependencies. The basic syntax includes the plugin name and dependency flags that control how dependencies are processed.

**Interactive Installation** prompts for each optional dependency, allowing users to make informed decisions about what to install. Required dependencies are installed automatically without prompting.

```bash
bin/cake manifest install --plugin YourPlugin --with-dependencies
```

When you run this command, the system displays information about each dependency including whether it's required or optional, which asset tags will be installed, and the reason it's needed. For optional dependencies, you'll be prompted to confirm installation.

```
Dependencies found:
  ✓ CorePlugin (required) - Provides essential core functionality
  ? CachePlugin (optional) - Enables response caching

Install CorePlugin plugin assets? [Y/n] y
Install CachePlugin plugin assets? [y/N] y

Dependency installation order:
  1. CorePlugin (config, migrations)
  2. CachePlugin (config)

Proceed with installation? [Y/n] y
```

**Install All Dependencies** skips prompts and installs all dependencies automatically, including optional ones. This is useful for automated deployments or when you want the complete feature set.

```bash
bin/cake manifest install --plugin YourPlugin --with-dependencies --all-deps
```

**Skip Dependencies** installs only the plugin itself without any dependencies. Use this when dependencies are already installed or when you want to install them separately later.

```bash
bin/cake manifest install --plugin YourPlugin --no-dependencies
```

**Dry Run with Dependencies** previews what would be installed without making actual changes. This includes showing which dependencies would be installed and in what order, allowing you to verify the installation plan before proceeding.

```bash
bin/cake manifest install --plugin YourPlugin --with-dependencies --dry-run
```

The system resolves the correct installation order automatically using topological sorting. If Plugin A depends on Plugin B, and Plugin B depends on Plugin C, the installation order will be C, then B, then A. This ensures all dependencies are available when needed.

**Circular Dependency Detection** prevents infinite loops by analyzing the dependency graph before installation begins. If a circular dependency is detected (Plugin A depends on B, and B depends on A), the system reports the circular chain and stops installation.

```
Error: Circular dependency detected:
  YourPlugin → HelperPlugin → CorePlugin → YourPlugin

Please resolve the circular dependency before installation.
```

**Missing Dependency Handling** occurs when a required dependency is not found or is not loaded in the application. The system provides clear error messages indicating which dependency is missing and suggests solutions.

```
Error: Required dependency 'CorePlugin' not found.

The plugin is either not loaded or does not implement ManifestInterface.
Please ensure the plugin is installed and loaded in your application.
```

### Dependency Best Practices

Declare only direct dependencies in your manifest rather than listing transitive dependencies. If your plugin depends on Plugin A, and Plugin A depends on Plugin B, only declare Plugin A as your dependency. The system will handle Plugin B automatically when Plugin A is installed.

Provide clear and descriptive reasons for each dependency. Users should understand why each dependency is needed and what functionality it provides. Good reasons explain the specific features or capabilities that the dependency enables.

Use conditional dependencies when functionality is optional or environment-specific. This gives users flexibility to install only what they need while ensuring all required components are available when certain features are enabled.

Group related functionality under optional dependencies rather than making everything required. This allows users to install a minimal configuration and add features as needed, reducing initial setup complexity.

Test your plugin with and without optional dependencies to ensure basic functionality works in minimal configurations. Optional dependencies should enhance features rather than break core functionality when absent.

### Updating Dependencies After Composer Updates

When you run `composer update`, dependent plugins may receive updates that include new migrations, configuration options, or other assets. The manifest system provides a dedicated workflow for updating dependency assets without reinstalling the parent plugin.

**First Installation:**
```bash
bin/cake manifest install --plugin MyPlugin --with-dependencies
```

This installs MyPlugin and all its dependencies. The registry tracks which dependencies were installed and when.

**After Composer Update:**
```bash
bin/cake manifest install --plugin MyPlugin --update-dependencies
```

This command automatically enables both `--with-dependencies` and `--existing` flags, which means it will reinstall all previously installed dependencies, updating existing files and adding new assets like migrations. The parent plugin MyPlugin is not reinstalled unless it also has new assets.

The update process works exactly like the root plugin update: migrations can be re-run to add new ones, config files respect the `copy-safe` setting, and bootstrap appends won't duplicate thanks to marker tracking. Environment variables check each var individually and only add missing ones.

**Alternative approach** for selective updates:
```bash
bin/cake manifest install --plugin CorePlugin --existing
```

You can also update individual dependency plugins directly by targeting them with the `--existing` flag. This gives you fine-grained control when you know which specific dependency received updates.

<a name="command-reference"></a>
## Command Reference

### Interactive Mode
```bash
bin/cake manifest install
```

When run without arguments, the command prompts the user to select which plugin and which tag to install, showing asset counts for each available tag to help with the decision.

### Install by Plugin
```bash
bin/cake manifest install --plugin Monitor
```

Installs all assets from the specified plugin.

### Install by Tag
```bash
bin/cake manifest install --plugin Monitor --tag config
```

Installs only assets with the specified tag.

### Install All
```bash
bin/cake manifest install --all
```

Installs assets from all plugins implementing `ManifestInterface`.

### Force Overwrite
```bash
bin/cake manifest install --plugin Monitor --force
```

Overwrites existing files (except `copy-safe` operations).

### Update Existing Only
```bash
bin/cake manifest install --plugin Monitor --existing
```

Only updates files that were previously installed.

### Dry Run
```bash
bin/cake manifest install --plugin Monitor --dry-run
```

Preview what would be installed without making any changes.

### Install with Dependencies
```bash
bin/cake manifest install --plugin Monitor --with-dependencies
```

Installs the plugin along with its declared dependencies. The command prompts for optional dependencies and automatically installs required ones.

### Install All Dependencies
```bash
bin/cake manifest install --plugin Monitor --with-dependencies --all-deps
```

Installs the plugin and all dependencies without prompting, including optional dependencies.

### Skip Dependencies
```bash
bin/cake manifest install --plugin Monitor --no-dependencies
```

Installs only the plugin itself, skipping all dependencies even if declared in the manifest.

### Update Dependencies
```bash
bin/cake manifest install --plugin Monitor --update-dependencies
```

Re-installs existing dependencies to pick up new migrations or configuration changes after composer updates. This uses the `--existing` flag internally to update previously installed assets while adding new ones. Particularly useful when dependency plugins receive updates that add new migrations or configuration options.

**Use case:** After running `composer update`, dependent plugins may have new migrations or configuration files. Use this command to update all dependency assets without reinstalling the parent plugin itself.

<a name="tag-system"></a>
## Tag System

Tags organize your assets into logical groups. Common tags include `config` for configuration files, `migrations` for database migrations, `templates` for view templates, `webroot` for public assets like CSS and JavaScript, `bootstrap` for bootstrap code, `envs` for environment variables, `locales` for translation files, and `docs` for documentation. Tags allow users to selectively install only what they need from your plugin.

The system provides a `Tag` class with constants for all standard tag names, preventing typos and providing IDE autocomplete support:

```php
use Crustum\PluginManifest\Manifest\Tag;

Tag::CONFIG       // 'config'
Tag::MIGRATIONS   // 'migrations'
Tag::WEBROOT      // 'webroot'
Tag::BOOTSTRAP    // 'bootstrap'
Tag::ENVS         // 'envs'
Tag::DEPENDENCIES // 'dependencies'
```

The `ManifestTrait` helper methods use these constants automatically, so you don't need to specify them manually. However, you can use them when creating custom asset definitions or when filtering by tag in the install command.

<a name="registry-and-tracking"></a>
## Registry and Tracking

The plugin tracks installed assets in `config/manifest_registry.php` with operation-specific rules:

**Can be re-installed:**
- `copy` - With `--force` or `--existing` flags
- `append-env` - Can re-run to add new variables

**Once only (tracked):**
- `append` - Bootstrap appends tracked by marker
- `merge` - Config merges tracked by key
- `copy-safe` - Never overwrites

<a name="advanced-examples"></a>
## Advanced Examples

### Complete Plugin Example

```php
<?php
namespace Monitor;

use Cake\Core\BasePlugin;
use PluginManifest\Manifest\ManifestInterface;
use PluginManifest\Manifest\ManifestTrait;

class MonitorPlugin extends BasePlugin implements ManifestInterface
{
    use ManifestTrait;

    public static function manifest(): array
    {
        $pluginPath = dirname(__DIR__) . DS;

        return [
            static::manifestConfig(
                $pluginPath . 'config' . DS . 'monitor.php.default',
                CONFIG . 'monitor.php',
                [],
                'config'
            ),

            static::manifestMigrations(
                $pluginPath . 'config' . DS . 'Migrations',
                CONFIG . 'Migrations',
                [],
                'migrations',
                'Monitor'
            ),

            static::manifestBootstrapAppend(
                "Plugin::load('Monitor', ['bootstrap' => true]);",
                CONFIG . 'bootstrap_after.php',
                [],
                'bootstrap',
                '// Monitor Plugin'
            ),

            static::manifestEnvVars(
                [
                    'MONITOR_ENABLED' => 'true',
                    'MONITOR_REDIS_HOST' => '127.0.0.1',
                    'MONITOR_REDIS_PORT' => '6379',
                ],
                ROOT . DS . '.env',
                [],
                'envs',
                '# Monitor Plugin Configuration'
            ),

            static::manifestConfigMerge(
                'Monitor',
                [
                    'enabled' => true,
                    'redis' => [
                        'host' => env('MONITOR_REDIS_HOST', '127.0.0.1'),
                        'port' => env('MONITOR_REDIS_PORT', 6379),
                    ],
                    'workers' => [
                        'default' => 3,
                    ],
                ],
                CONFIG . 'app_local.php',
                [],
                'config'
            ),
        ];
    }
}
```

### Custom Asset Definition

You can also define custom assets without using trait helpers:

```php
public static function manifest(): array
{
    return [
        [
            'type' => 'copy',
            'source' => __DIR__ . '/templates/Custom',
            'destination' => ROOT . DS . 'templates' . DS . 'Custom',
            'tag' => 'templates',
        ],
        [
            'type' => 'append',
            'destination' => CONFIG . 'routes.php',
            'content' => "\$routes->plugin('YourPlugin', function (\$routes) {\n    \$routes->fallbacks();\n});",
            'marker' => '// YourPlugin routes',
            'tag' => 'routes',
        ],
    ];
}
```

### Webroot Assets

```php
static::manifestWebroot(
    __DIR__ . '/webroot',
    WWW_ROOT . 'your_plugin',
    [],
    'webroot'
)
```

Generates:
```php
[
    'type' => 'copy-safe',
    'source' => __DIR__ . '/webroot',
    'destination' => WWW_ROOT . 'your_plugin',
    'tag' => 'webroot',
]
```

## How It Works

### 1. Plugin Discovery

The command scans all loaded plugins for those implementing `ManifestInterface`:

```php
foreach (Plugin::loaded() as $pluginName) {
    $plugin = Plugin::getCollection()->get($pluginName);
    $pluginClass = get_class($plugin);

    if (is_subclass_of($pluginClass, ManifestInterface::class)) {
        $assets = $pluginClass::manifest();
        // Process assets
    }
}
```

### 2. Asset Organization

Assets are organized by tag for easy filtering:

```php
[
    'Monitor' => [
        'config' => [/* config assets */],
        'migrations' => [/* migration assets */],
        'bootstrap' => [/* bootstrap assets */],
    ],
]
```

### 3. Installation

The `Installer` service processes each asset based on its type:

```php
$installer->install($asset, $options);
```

The install method accepts several options: `force` to overwrite existing files, `existing` to only update previously installed files, and `dry_run` to preview changes without applying them.

### 4. Registry Tracking

The `ManifestRegistry` tracks installed assets with operation-specific rules:

```php
// Can be re-installed
'copy' => true,
'append-env' => true,

// Once only
'append' => false,
'merge' => false,
'copy-safe' => false,
```

## Operation Reference

### manifestConfig()

```php
static::manifestConfig(
    string $source,           // Source file path
    string $destination,      // Destination file path
    array $options = [],      // Additional options
    string $tag = 'config'    // Tag for grouping
): array
```

Creates a `copy-safe` operation (never overwrites).

### manifestMigrations()

```php
static::manifestMigrations(
    string $sourceDir,           // Source migrations directory
    string $destinationDir,      // Destination directory
    array $options = [],         // Additional options
    string $tag = 'migrations',  // Tag for grouping
    string $pluginNamespace = '' // Plugin namespace to add
): array
```

Creates a `copy` operation with migration namespace handling.

### manifestBootstrapAppend()

```php
static::manifestBootstrapAppend(
    string $content,                        // Code to append
    string $bootstrapFile = 'bootstrap.php', // Target file
    array $options = [],                     // Additional options
    string $tag = 'bootstrap',               // Tag for grouping
    ?string $marker = null                   // Optional marker for duplicate detection
): array
```

Creates an `append` operation for bootstrap files.

### manifestEnvVars()

```php
static::manifestEnvVars(
    array $envVars,               // ['KEY' => 'value', ...]
    string $envFile = '.env',     // Target .env file
    array $options = [],          // Additional options
    string $tag = 'envs',         // Tag for grouping
    ?string $comment = null       // Optional comment to add before vars
): array
```

Creates an `append-env` operation.

### manifestConfigMerge()

```php
static::manifestConfigMerge(
    string $key,                     // Config key
    mixed $value,                    // Config value
    string $configFile = 'app_local.php', // Target config file
    array $options = [],             // Additional options
    string $tag = 'config'           // Tag for grouping
): array
```

Creates a `merge` operation that preserves comments.

### manifestWebroot()

```php
static::manifestWebroot(
    string $source,                     // Source webroot directory
    string|null $destination = null     // Destination (defaults to WWW_ROOT/pluginname)
): array
```

Creates a `copy` operation for public assets (CSS, JS, images).

### manifestEnvExample()

```php
static::manifestEnvExample(
    string $source                      // Source .env.example file
): array
```

Copies `.env.example` file as plugin-specific example (e.g., `.env.yourplugin.example`). Never overwrites existing files.

### manifestBootstrapAfter()

```php
static::manifestBootstrapAfter(
    string $content,                    // Code to append
    string|null $marker = null          // Marker comment (defaults to plugin name)
): array
```

Appends code to `bootstrap_after.php`. Shortcut for `manifestBootstrapAppend()` with `bootstrap_after.php` file.

### manifestPluginBootstrapAfter()

```php
static::manifestPluginBootstrapAfter(
    string $content,                    // Code to append
    string|null $marker = null          // Marker comment (defaults to plugin name)
): array
```

Creates a `copy-safe` operation for public assets.
Appends code to `plugin_bootstrap_after.php`. Shortcut for `manifestBootstrapAppend()` with `plugin_bootstrap_after.php` file.

### manifestDependencies()

```php
static::manifestDependencies(
    array $dependencies                 // Dependency configuration
): array
```

Declares plugin dependencies. Each dependency can be required or optional, specify which tags to install, and include conditions for installation. The configuration array maps plugin names to their dependency settings including `required` (boolean), `tags` (array of tags to install), `reason` (explanation), `prompt` (user prompt text), `condition` (evaluation type), and condition-specific keys like `condition_path` or `condition_key`.

**Example:**
```php
use Crustum\PluginManifest\Manifest\Tag;

static::manifestDependencies([
    'CorePlugin' => [
        'required' => true,
        'tags' => [Tag::CONFIG, Tag::MIGRATIONS],
        'reason' => 'Essential functionality',
    ],
    'CachePlugin' => [
        'required' => false,
        'tags' => [Tag::CONFIG],
        'prompt' => 'Install caching?',
        'condition' => 'file_exists',
        'condition_path' => CONFIG . 'cache.php',
    ],
])
```

## Best Practices

### 1. Use Copy-Safe for User Files

Files that users will customize should use `copy-safe`:

```php
static::manifestConfig(
    $pluginPath . 'config' . DS . 'your_plugin.php.default',
    CONFIG . 'your_plugin.php',
    [],
    'config'
)
```

### 2. Use Markers for Bootstrap Appends

Always include markers for bootstrap appends:

```php
static::manifestBootstrapAppend(
    "Plugin::load('YourPlugin');",
    CONFIG . 'bootstrap.php',
    [],
    'bootstrap',
    '// YourPlugin - DO NOT REMOVE'
)
```

### 3. Group Assets with Tags

Organize assets by purpose:

```php
return [
    // Essential config
    static::manifestConfig(..., 'config'),

    // Database schema
    static::manifestMigrations(..., 'migrations'),

    // Optional features
    static::manifestConfig(..., 'optional'),

    // Development tools
    static::manifestConfig(..., 'dev'),
];
```

### 4. Provide Default Values

Use environment variables with defaults in merged configs:

```php
static::manifestConfigMerge(
    'YourPlugin',
    [
        'api_key' => env('YOUR_PLUGIN_API_KEY', ''),
        'timeout' => env('YOUR_PLUGIN_TIMEOUT', 30),
        'enabled' => env('YOUR_PLUGIN_ENABLED', true),
    ],
    CONFIG . 'app_local.php',
    [],
    'config'
)
```

### 5. Document Your Assets

Add comments in your `manifest()` method:

```php
return [
    // Core configuration file (required)
    static::manifestConfig(...),

    // Database migrations (required for database features)
    static::manifestMigrations(...),

    // Environment variables (optional, for customization)
    static::manifestEnvVars(...),
];
```

## Migration Namespace Example

**Source:** `plugins/YourPlugin/config/Migrations/20250101000000_CreateItemsTable.php`
```php
class CreateItemsTable extends AbstractMigration
{
    // ...
}
```

**Installed:** `config/Migrations/20250101000000_YourPluginCreateItemsTable.php`
```php
class YourPluginCreateItemsTable extends AbstractMigration
{
    // ...
}
```

## Error Handling

The plugin provides detailed error messages:

```
[✓] config/your_plugin.php → config/your_plugin.php
[SKIP] config/existing.php (File exists, use --force to overwrite)
[✗] config/invalid.php: File does not exist
[DRY] Would append to config/bootstrap.php
```

Status types:
- `installed` - Successfully installed
- `skipped` - Already exists or completed
- `error` - Installation failed
- `would-*` - Dry run preview

## License

Licensed under the MIT License.

