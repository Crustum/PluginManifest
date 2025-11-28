<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Manifest;

/**
 * Tag constants
 *
 * Defines all supported asset tag names for grouping and filtering.
 */
class Tag
{
    public const CONFIG = 'config';
    public const MIGRATIONS = 'migrations';
    public const WEBROOT = 'webroot';
    public const BOOTSTRAP = 'bootstrap';
    public const ENVS = 'envs';
    public const DEPENDENCIES = 'dependencies';
}
