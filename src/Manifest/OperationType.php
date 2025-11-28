<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Manifest;

/**
 * Operation type constants
 *
 * Defines all supported asset installation operation types.
 */
class OperationType
{
    public const COPY = 'copy';
    public const COPY_SAFE = 'copy-safe';
    public const APPEND = 'append';
    public const APPEND_ENV = 'append-env';
    public const MERGE = 'merge';
    public const DEPENDENCIES = 'dependencies';
}
