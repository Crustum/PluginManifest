# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.0]

### Added

- Inspector-backed plugin discovery (`crustum/inspector ^1.0.3`): composer-installed `cakephp-plugin` packages are found even when not loaded, via `ProjectScan::cakePluginDetails()` without loading anything
- `--path` option on `manifest install` / `manifest list` for local plugin checkouts composer does not know about (repeatable)
- Automatic plugin registration in `config/plugins.php` before asset installation (including dependencies, in order), mirroring the core `plugin load` write mechanism
- Manifest provider load options: boolean props `$bootstrap`, `$routes`, `$middleware`, `$console`, `$services` plus `$dev`/`$cli` (`onlyDebug`/`onlyCli`) on `{Namespace}\Manifest\{Short}Manifest` classes
- `ManifestResolver::loadOptions()` resolving provider load props without instantiating anything
- `PluginLoader` service with once-per-run confirmation (`--all-deps` and `--dry-run` skip prompting)
- `(not loaded)` marker in `manifest list` output
- Tests for Inspector discovery, provider load options, and plugin loading

### Changed

- Unloaded plugin names resolve to namespace path (`Crustum/Ai`), matching the house convention in `config/plugins.php`
- Registration is file-first: only `config/plugins.php` content decides, the runtime collection is never consulted for writes
- All plugin existence checks use side-effect-free `has()` instead of auto-loading `get()`
- Dependency installation resolves unloaded plugins from discovery instead of failing as "not available"

### Fixed

- Installing `--all` no longer silently skips already runtime-loaded plugins for `plugins.php` registration
- Short provider-derived names (e.g. `Ai`) no longer poison `config/plugins.php` and crash boot via `create('Ai')`
- Required dependencies install by default; missing required dependencies abort with a clear error instead of silent skip
- `manifest list` counts each (type, tag) registry bucket once per tag instead of once per asset
- `manifest list` no longer shows star-repo prompt rows
- Registry no longer appends identical records on repeated installs
- Skipped-but-present files are recorded as installed, so second runs converge
- Dependency installation skips star-repo assets instead of failing with "Unknown install type"

## [1.0.2]

### Added

- Support for dot-notation paths in `manifestConfigMerge()` to merge into nested structures (e.g., 'Notification.channels.slack')
- `RawValue` class to preserve PHP expressions like `env()` calls during config merging
- `manifestStarRepo()` method to `ManifestTrait` for enabling GitHub star prompts
- `recordStarPromptShown()` and `hasStarPromptBeenShown()` methods to `ManifestRegistry` for tracking star prompts
- `STAR_REPO` operation type and tag constants
- `StarRepo` helper class for handling repository prompts with Packagist auto-detection
- `PluginDiscovery`, `OutputFormatter`, `StarRepo`, and `Installation` helper classes extracted from `ManifestInstallCommand`
- `getPluginName()` method to `ManifestTrait` to eliminate code duplication
- `getActualPluginName()` method to `DependencyInstaller` to resolve plugin names from `Plugin::loaded()`
- `recordAssetInstallation()` method to `DependencyInstaller` to properly track dependency installations
- Tests for dot-notation merging (`testMergeDeepNestedPath()`)
- Tests for RawValue preservation (`testMergeWithRawValue()`)

### Changed

- `manifestConfigMerge()` now supports dot-notation for nested key paths
- `promptForSelection()` split into `promptForPlugin()` and `promptForTag()` for two-level menu interaction
- Installation logic moved to `Installation` helper class for better separation of concerns
- All manifest methods in `ManifestTrait` now use `getPluginName()` helper method
- `ManifestRegistry::getStatus()` now excludes `_star_prompts` from status calculations
- `STAR_REPO` tag filtered out from interactive selection menu
- Registry parameter renamed to `manifest` in `DependencyInstaller` for consistency

### Fixed

- Registry recording for dependency installations now works correctly
- PHP expressions like `env()` calls are now preserved in merged config files instead of being evaluated to `NULL`

### Removed

- Duplicate registry recording code from `Installer` class (moved to helper classes)
- Unnecessary registry recording from append and merge operations in `Installer`
- `tests/README.md` file

## [1.0.1]

### Added

- Initial documentation for dot-notation config merging
- Examples for preserving env() calls with RawValue
- Updated manifestConfigMerge() reference documentation with new features

## [1.0.0]

### Added

- Plugin manifest system for distributing installable assets
- ManifestInterface for plugins to define installable assets
- ManifestTrait providing helper methods for defining assets
- ManifestRegistry for tracking installed plugin assets
- Support for config file installation (copy and copy-safe)
- Support for migration installation with plugin namespace
- Support for webroot asset installation
- Support for bootstrap file appending
- Support for environment variable installation
- Support for config merging while preserving comments
- Support for plugin dependency installation
- ManifestInstallCommand for installing plugin assets
- Dry-run mode for previewing installations
- Interactive selection menu for choosing assets to install
- Registry persistence to track completed installations

