# PluginManifest Test Suite

## Overview

Comprehensive test suite for the PluginManifest plugin with real file operations testing.

## Test Structure

### Unit Tests

#### `BootstrapAppenderTest.php`
Tests for appending code to bootstrap files:
- ✅ Append to bootstrap file with marker
- ✅ Skip when marker already exists
- ✅ Skip when content already exists
- ✅ Error handling for missing files
- ✅ Dry run mode
- ✅ Append without marker
- ✅ Append multiple lines

#### `ConfigMergerTest.php`
Tests for merging configurations:
- ✅ Merge configuration into existing file
- ✅ Skip when key already exists
- ✅ Error handling for missing files
- ✅ Dry run mode
- ✅ Preserve comments in config files
- ✅ Merge complex nested values

#### `EnvInstallerTest.php`
Tests for environment variable installation:
- ✅ Append environment variables
- ✅ Skip existing variables
- ✅ Create file if not exists
- ✅ Dry run mode
- ✅ Handle values with spaces and quotes
- ✅ Empty vars array
- ✅ Append comment before variables

#### `ManifestRegistryTest.php`
Tests for tracking installed assets:
- ✅ Record installed assets
- ✅ Record multiple installations
- ✅ Check append operation completion
- ✅ Check merge operation completion
- ✅ Append-env always returns false (can re-run)
- ✅ Can reinstall check by operation type
- ✅ Get installed with filters (plugin/type/tag)
- ✅ Persistence across registry instances

### Integration Tests

#### `InstallerIntegrationTest.php`
End-to-end tests with real file operations:
- ✅ Copy operation (file)
- ✅ Copy operation skips existing file
- ✅ Copy operation with --force flag
- ✅ Copy-safe never overwrites
- ✅ Copy directory recursively
- ✅ Append operation to bootstrap
- ✅ Append operation skips duplicate
- ✅ Append-env operation
- ✅ Merge operation to config
- ✅ Merge operation skips existing key
- ✅ Migration installation with plugin namespace
- ✅ Dry run mode

### Test Plugin

#### `TestPlugin/`
A sample plugin implementing `ManifestInterface`:
- Config file: `config/test_config.php`
- Migration: `config/Migrations/20250101000000_CreateTestTable.php`
- Implements all manifest operations:
  - Config copy
  - Migration installation
  - Bootstrap append
  - Env vars
  - Config merge

## Running Tests

### All Tests
```bash
cd plugins/PluginManifest
php ../../vendor/bin/phpunit --configuration phpunit.xml.dist
```

### Specific Test
```bash
php ../../vendor/bin/phpunit tests/TestCase/Manifest/BootstrapAppenderTest.php
```

### Test Verification Script (Windows)
```powershell
powershell -ExecutionPolicy Bypass -File run-tests.ps1
```

## Test Coverage

### Operations Tested
- ✅ **copy**: Standard file/directory copy
- ✅ **copy-safe**: Never overwrites existing files
- ✅ **append**: Append code to bootstrap files
- ✅ **append-env**: Append environment variables
- ✅ **merge**: Merge configuration while preserving comments
- ✅ **migrations**: Install migrations with plugin namespace

### Scenarios Tested
- ✅ Normal operation
- ✅ File already exists
- ✅ Force overwrite
- ✅ Dry run mode
- ✅ Missing source files
- ✅ Directory operations
- ✅ Duplicate prevention
- ✅ Comment preservation
- ✅ Complex nested values
- ✅ Registry persistence

### Edge Cases Tested
- ✅ Files with spaces in values
- ✅ Files with quotes
- ✅ Empty arrays
- ✅ Non-existent files
- ✅ Large files (bootstrap overload detection)
- ✅ Marker-based duplicate detection
- ✅ Content-based duplicate detection

## Test Features

### Isolation
- Each test uses temporary directories
- Automatic cleanup in `tearDown()`
- No interference between tests
- Real file system operations

### Coverage
- Unit tests for individual services
- Integration tests for complete workflows
- Edge case handling
- Error scenarios

### Maintainability
- Clear test names
- Well-documented assertions
- Organized by service/feature
- Reusable test helpers

## Future Enhancements

### Planned Tests
- [ ] Command integration tests
- [ ] Interactive mode tests
- [ ] Plugin discovery tests
- [ ] Error reporting tests
- [ ] Large batch operations
- [ ] Concurrent installation tests

### Performance Tests
- [ ] Large directory copy performance
- [ ] Multiple plugin installation
- [ ] Registry performance with many entries

### Security Tests
- [ ] Path traversal prevention
- [ ] Permission handling
- [ ] File size limits
- [ ] Invalid input handling

