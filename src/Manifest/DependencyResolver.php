<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Manifest;

use Cake\Core\Configure;
use InvalidArgumentException;

/**
 * Resolves plugin dependencies and detects circular dependencies
 *
 * Provides topological sorting for dependency order and circular dependency detection
 * to ensure plugins are installed in the correct order without conflicts.
 */
class DependencyResolver
{
    /**
     * Resolve dependency installation order using topological sort
     *
     * @param array<string, array<string, mixed>> $allDependencies All plugin dependencies
     * @return array<string> Ordered list of plugins to install
     * @throws \InvalidArgumentException When circular dependencies are detected
     */
    public function resolveDependencyOrder(array $allDependencies): array
    {
        $circularDeps = $this->checkCircularDependencies($allDependencies);
        if (!empty($circularDeps)) {
            throw new InvalidArgumentException(
                'Circular dependencies detected: ' . implode(' → ', $circularDeps),
            );
        }

        return $this->topologicalSort($allDependencies);
    }

    /**
     * Check for circular dependencies
     *
     * @param array<string, array<string, mixed>> $allDependencies All plugin dependencies
     * @return array<string> Circular dependency chain if found, empty array if none
     */
    public function checkCircularDependencies(array $allDependencies): array
    {
        $visited = [];
        $recursionStack = [];

        foreach (array_keys($allDependencies) as $plugin) {
            if (!isset($visited[$plugin])) {
                $cycle = $this->detectCycleRecursive(
                    $plugin,
                    $allDependencies,
                    $visited,
                    $recursionStack,
                    [],
                );
                if (!empty($cycle)) {
                    return $cycle;
                }
            }
        }

        return [];
    }

    /**
     * Perform topological sort to determine installation order
     *
     * @param array<string, array<string, mixed>> $allDependencies All plugin dependencies
     * @return array<string> Ordered list of plugins
     */
    protected function topologicalSort(array $allDependencies): array
    {
        $inDegree = [];
        $graph = [];
        $allPlugins = array_keys($allDependencies);

        foreach ($allPlugins as $plugin) {
            $inDegree[$plugin] = 0;
            $graph[$plugin] = [];
        }

        foreach ($allDependencies as $plugin => $deps) {
            if (isset($deps['dependencies']) && is_array($deps['dependencies'])) {
                foreach (array_keys($deps['dependencies']) as $dependency) {
                    if (in_array($dependency, $allPlugins)) {
                        $graph[$dependency][] = $plugin;
                        $inDegree[$plugin]++;
                    }
                }
            }
        }

        $queue = [];
        foreach ($inDegree as $plugin => $degree) {
            if ($degree === 0) {
                $queue[] = $plugin;
            }
        }

        $result = [];
        while (!empty($queue)) {
            $current = array_shift($queue);
            $result[] = $current;

            foreach ($graph[$current] as $neighbor) {
                $inDegree[$neighbor]--;
                if ($inDegree[$neighbor] === 0) {
                    $queue[] = $neighbor;
                }
            }
        }

        return $result;
    }

    /**
     * Recursively detect cycles in dependency graph
     *
     * @param string $plugin Current plugin being checked
     * @param array<string, array<string, mixed>> $allDependencies All plugin dependencies
     * @param array<string, bool> $visited Visited plugins
     * @param array<string, bool> $recursionStack Current recursion stack
     * @param array<string> $path Current path for cycle detection
     * @return array<string> Cycle path if found, empty array if none
     */
    protected function detectCycleRecursive(
        string $plugin,
        array $allDependencies,
        array &$visited,
        array &$recursionStack,
        array $path,
    ): array {
        $visited[$plugin] = true;
        $recursionStack[$plugin] = true;
        $path[] = $plugin;

        if (isset($allDependencies[$plugin]['dependencies'])) {
            foreach (array_keys($allDependencies[$plugin]['dependencies']) as $dependency) {
                $dependencyString = (string)$dependency;
                if (!isset($visited[$dependencyString])) {
                    $cycle = $this->detectCycleRecursive(
                        $dependencyString,
                        $allDependencies,
                        $visited,
                        $recursionStack,
                        $path,
                    );
                    if (!empty($cycle)) {
                        return $cycle;
                    }
                } elseif (isset($recursionStack[$dependencyString]) && $recursionStack[$dependencyString]) {
                    $cycleStart = array_search($dependencyString, $path, true);
                    $offset = $cycleStart === false ? 0 : (int)$cycleStart;

                    return array_slice($path, $offset);
                }
            }
        }

        $recursionStack[$plugin] = false;
        array_pop($path);

        return [];
    }

    /**
     * Filter dependencies by requirements and conditions
     *
     * @param array<string, array<string, mixed>> $dependencies Raw dependencies
     * @param array<string, mixed> $options Installation options
     * @return array<string, array<string, mixed>> Filtered dependencies
     */
    public function filterDependencies(array $dependencies, array $options = []): array
    {
        $filtered = [];
        $installOptional = $options['install_optional'] ?? false;
        $forceAll = $options['force_all'] ?? false;

        foreach ($dependencies as $pluginName => $config) {
            $required = $config['required'] ?? false;

            if (!$required && !$forceAll && !$installOptional) {
                continue;
            }

            $hasCondition = isset($config['condition']);
            if ($forceAll) {
                $filtered[$pluginName] = $config;
                continue;
            }

            if (!$hasCondition) {
                $filtered[$pluginName] = $config;
                continue;
            }

            if ($this->evaluateCondition($config['condition'], $config)) {
                $filtered[$pluginName] = $config;
            }
        }

        return $filtered;
    }

    /**
     * Evaluate dependency condition
     *
     * @param mixed $condition Condition to evaluate
     * @param array<string, mixed> $config Dependency configuration
     * @return bool Whether condition is met
     */
    protected function evaluateCondition(mixed $condition, array $config): bool
    {
        if (is_string($condition)) {
            switch ($condition) {
                case 'file_exists':
                    return isset($config['condition_path']) && file_exists($config['condition_path']);
                case 'config_exists':
                    return isset($config['condition_key']) &&
                           class_exists('Cake\Core\Configure') &&
                           Configure::check($config['condition_key']);
                default:
                    return false;
            }
        }

        if (is_callable($condition)) {
            return $condition();
        }

        return (bool)$condition;
    }

    /**
     * Get dependency information for display
     *
     * @param array<string, array<string, mixed>> $dependencies Dependencies configuration
     * @return array<string, array<string, mixed>> Formatted dependency info
     */
    public function getDependencyInfo(array $dependencies): array
    {
        $info = [];

        foreach ($dependencies as $pluginName => $config) {
            $hasCondition = isset($config['condition']);
            $info[$pluginName] = [
                'required' => $config['required'] ?? false,
                'tags' => $config['tags'] ?? null,
                'reason' => $config['reason'] ?? 'No reason provided',
                'prompt' => $config['prompt'] ?? "Install {$pluginName} plugin assets?",
                'condition' => $config['condition'] ?? null,
                'condition_met' => $hasCondition ? $this->evaluateCondition($config['condition'], $config) : true,
            ];
        }

        return $info;
    }

    /**
     * Build complete dependency tree recursively
     *
     * Discovers all transitive dependencies and returns them in installation order
     * (deepest dependencies first).
     *
     * @param array<string, array<string, mixed>> $availablePlugins Available plugins with manifests
     * @param array<string> $rootPlugins Plugins to install (starting point)
     * @param array<string, mixed> $options Filter options
     * @return array<string, array<string, mixed>> Ordered dependencies to install
     * @throws \InvalidArgumentException When circular dependencies are detected
     */
    public function buildDependencyTree(
        array $availablePlugins,
        array $rootPlugins,
        array $options = [],
    ): array {
        $allDependencies = [];
        $visited = [];

        foreach ($rootPlugins as $plugin) {
            $this->discoverDependenciesRecursive(
                $plugin,
                $availablePlugins,
                $allDependencies,
                $visited,
                $options,
            );
        }

        if (empty($allDependencies)) {
            return [];
        }

        $circularDeps = $this->checkCircularDependencies($allDependencies);
        if (!empty($circularDeps)) {
            throw new InvalidArgumentException(
                'Circular dependencies detected: ' . implode(' → ', $circularDeps),
            );
        }

        $orderedPlugins = $this->topologicalSort($allDependencies);

        $result = [];
        foreach ($orderedPlugins as $pluginName) {
            if (isset($allDependencies[$pluginName]['config'])) {
                $result[$pluginName] = $allDependencies[$pluginName]['config'];
            }
        }

        return $result;
    }

    /**
     * Recursively discover dependencies for a plugin
     *
     * @param string $pluginName Plugin to discover dependencies for
     * @param array<string, array<string, mixed>> $availablePlugins Available plugins
     * @param array<string, array<string, mixed>> $allDependencies Accumulated dependencies
     * @param array<string, bool> $visited Visited plugins
     * @param array<string, mixed> $options Filter options
     * @return void
     */
    protected function discoverDependenciesRecursive(
        string $pluginName,
        array $availablePlugins,
        array &$allDependencies,
        array &$visited,
        array $options,
    ): void {
        if (isset($visited[$pluginName])) {
            return;
        }

        $visited[$pluginName] = true;

        if (!isset($availablePlugins[$pluginName])) {
            return;
        }

        $pluginAssets = $availablePlugins[$pluginName]['assets'] ?? [];
        $dependencyAsset = null;

        foreach ($pluginAssets as $assets) {
            foreach ($assets as $asset) {
                if (($asset['type'] ?? '') === 'dependencies') {
                    $dependencyAsset = $asset;
                    break 2;
                }
            }
        }

        if ($dependencyAsset === null) {
            $allDependencies[$pluginName] = [
                'dependencies' => [],
                'config' => [],
            ];

            return;
        }

        $dependencies = $dependencyAsset['dependencies'] ?? [];
        $filtered = $this->filterDependencies($dependencies, $options);

        $dependenciesArray = [];
        foreach (array_keys($filtered) as $key) {
            $dependenciesArray[$key] = true;
        }

        $allDependencies[$pluginName] = [
            'dependencies' => $dependenciesArray,
            'config' => $filtered,
        ];

        foreach (array_keys($filtered) as $dependencyName) {
            $this->discoverDependenciesRecursive(
                $dependencyName,
                $availablePlugins,
                $allDependencies,
                $visited,
                $options,
            );
        }
    }
}
