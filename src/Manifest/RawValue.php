<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Manifest;

/**
 * Wrapper for raw PHP code that should be preserved as-is in config files
 *
 * Use this to preserve env() calls, function calls, or any PHP expressions
 * that should not be evaluated by var_export().
 *
 * Example:
 * ```php
 * 'host' => RawValue::raw("env('MONITOR_REDIS_HOST', '127.0.0.1')")
 * ```
 */
class RawValue
{
    /**
     * @param string $code Raw PHP code to output as-is
     */
    public function __construct(
        public readonly string $code,
    ) {
    }

    /**
     * Create a raw value wrapper
     *
     * @param string $code Raw PHP code
     * @return self
     */
    public static function raw(string $code): self
    {
        return new self($code);
    }
}
