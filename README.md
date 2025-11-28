# CakePHP PluginManifest Plugin

The **PluginManifest** plugin provides a standardized way for CakePHP plugins to install and publish assets (config files, migrations, templates, environment variables, and bootstrap code) to the main application.

This plugin enables plugin developers to distribute optional configuration files, migrations, and other assets that users can selectively install when needed.

The plugin uses interface-based registration where plugins implement `ManifestInterface`, making asset distribution simple and developer-friendly with interactive selection, dry-run mode, and smart duplicate prevention.

## Features

Plugins implement the `ManifestInterface` with a static `manifest()` method to define their publishable assets. The system supports multiple operation types including copying files, appending to bootstrap, merging configurations, installing migrations, and adding environment variables.

Migration handling is smart, automatically adding plugin namespaces to prevent class conflicts while preserving timestamps for correct ordering. Configuration merging preserves all user comments and file structure, ensuring customizations are never lost.

The interactive mode prompts users to select what to install, or you can install by plugin, tag, or all assets at once. Dry run mode lets you preview changes before applying them. The system uses marker and content-based duplicate detection and tracks installed assets with operation-specific rules. All generated code uses modern short array syntax.

## Requirements

* PHP 8.1+
* CakePHP 5.1+

## Documentation

For complete documentation, usage examples, and tutorials, see the [docs](docs/index.md) directory of this repository.

## Quick Example

Each trait method returns an array of assets, so use `array_merge()` to combine them:

```php
use Crustum\PluginManifest\Manifest\ManifestInterface;
use Crustum\PluginManifest\Manifest\ManifestTrait;

class YourPlugin extends BasePlugin implements ManifestInterface
{
    use ManifestTrait;

    public static function manifest(): array
    {
        $pluginPath = dirname(__DIR__) . DS;

        return array_merge(
            static::manifestConfig(
                $pluginPath . 'config' . DS . 'app.php',
                CONFIG . 'your_plugin.php',
                false
            ),
            static::manifestMigrations(
                $pluginPath . 'config' . DS . 'Migrations',
                CONFIG . 'Migrations'
            ),
            static::manifestBootstrapAppend(
                "Plugin::load('YourPlugin');",
                '// YourPlugin bootstrap'
            ),
            static::manifestEnvVars(
                [
                    'YOUR_PLUGIN_KEY' => 'default_value',
                ],
                '# YourPlugin Configuration'
            ),
            static::manifestConfigMerge(
                'YourPlugin',
                [
                    'enabled' => true,
                    'timeout' => 30,
                ]
            )
        );
    }
}
```

Then install with:
```bash
bin/cake manifest install --plugin YourPlugin
```

## License

Licensed under the [MIT](http://www.opensource.org/licenses/mit-license.php) License. Redistributions of the source code included in this repository must retain the copyright notice found in each file.

