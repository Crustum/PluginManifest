<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Test\TestPlugin\Manifest;

class PlainLoadManifest
{
    protected static bool $bootstrap = true;

    protected static bool $routes = false;

    protected static bool $dev = true;

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function manifest(): array
    {
        return [
            [
                'type' => 'copy',
                'source' => 'x',
                'destination' => 'y',
                'tag' => 'config',
            ],
        ];
    }
}
