# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

