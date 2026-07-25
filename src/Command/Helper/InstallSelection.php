<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Command\Helper;

use Cake\Console\ConsoleIo;
use Crustum\PluginManifest\Manifest\Tag;
use Crustum\Prompts\Console\Helper\MultiSelectHelper;

/**
 * Interactive install target selection via Crustum/Prompts MultiSelect.
 *
 * Page 1: plugins (All on top). After confirm:
 * - 0 selected → cancel
 * - 1 plugin → Page 2 tag MultiSelect
 * - 2+ plugins (or All) → all tags for each plugin
 *
 * Defaults are empty so the user must choose; Enter with no selection cancels.
 */
class InstallSelection
{
    public const ALL_PLUGINS = '__all_plugins__';

    public const ALL_TAGS = '__all_tags__';

    /**
     * Prompt for plugins (and tags when a single plugin is chosen).
     *
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @param array<string, array<string, mixed>> $plugins Discoverable plugins
     * @return list<array{plugin: string, tags: list<string>|null}>|null Selections or null if cancelled
     */
    public function prompt(ConsoleIo $io, array $plugins): ?array
    {
        $pluginNames = array_keys($plugins);
        $selectedPluginKeys = $this->promptForPlugins($io, $plugins);

        $resolvedPlugins = $this->resolveSelectedPlugins($selectedPluginKeys, $pluginNames);
        if ($resolvedPlugins === null) {
            return null;
        }

        if (count($resolvedPlugins) === 1) {
            $pluginName = $resolvedPlugins[0];
            $tags = $this->promptForTags($io, $pluginName, $plugins[$pluginName]);
            if ($tags === false) {
                return null;
            }

            return [[
                'plugin' => $pluginName,
                'tags' => $tags,
            ]];
        }

        $selections = [];
        foreach ($resolvedPlugins as $pluginName) {
            $selections[] = [
                'plugin' => $pluginName,
                'tags' => null,
            ];
        }

        return $selections;
    }

    /**
     * Resolve multi-select plugin keys into concrete plugin names.
     *
     * @param array<int|string> $selected Selected option keys
     * @param list<string> $pluginNames All plugin names
     * @return list<string>|null Plugin names or null if cancelled
     */
    public function resolveSelectedPlugins(array $selected, array $pluginNames): ?array
    {
        if ($selected === []) {
            return null;
        }

        if (in_array(self::ALL_PLUGINS, $selected, true)) {
            return $pluginNames;
        }

        $resolved = [];
        foreach ($selected as $key) {
            if (!is_string($key)) {
                continue;
            }

            if (in_array($key, $pluginNames, true)) {
                $resolved[] = $key;
            }
        }

        $resolved = array_values(array_unique($resolved));

        return $resolved === [] ? null : $resolved;
    }

    /**
     * Resolve multi-select tag keys into a tag filter.
     *
     * @param array<int|string> $selected Selected option keys
     * @param list<string> $tagNames Installable tag names
     * @return list<string>|false|null Tag list, null for all tags, or false if cancelled
     */
    public function resolveSelectedTags(array $selected, array $tagNames): array|null|false
    {
        if ($selected === []) {
            return false;
        }

        if (in_array(self::ALL_TAGS, $selected, true)) {
            return null;
        }

        $resolved = [];
        foreach ($selected as $key) {
            if (!is_string($key)) {
                continue;
            }

            if (in_array($key, $tagNames, true)) {
                $resolved[] = $key;
            }
        }

        $resolved = array_values(array_unique($resolved));

        if ($resolved === []) {
            return false;
        }

        if (count($resolved) === count($tagNames)) {
            return null;
        }

        return $resolved;
    }

    /**
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @param array<string, array<string, mixed>> $plugins Plugins
     * @return array<int|string> Selected keys
     */
    protected function promptForPlugins(ConsoleIo $io, array $plugins): array
    {
        $options = [
            self::ALL_PLUGINS => 'All plugins',
        ];

        foreach ($plugins as $pluginName => $pluginData) {
            $tags = array_keys($pluginData['assets']);
            $totalAssets = 0;
            foreach ($tags as $tag) {
                $totalAssets += count($pluginData['assets'][$tag]);
            }

            $options[$pluginName] = sprintf(
                '%s (%d asset(s) in %d tag(s))',
                $pluginName,
                $totalAssets,
                count($tags),
            );
        }

        $helper = $io->helper('Crustum/Prompts.MultiSelect');
        assert($helper instanceof MultiSelectHelper);

        $selected = $helper->run([
            'label' => 'Select plugins to install',
            'options' => $options,
            'default' => [],
            'scroll' => 15,
            'hint' => 'Space to toggle, Enter to confirm. One plugin opens tag selection; multiple installs all tags.',
        ]);

        return is_array($selected) ? $selected : [];
    }

    /**
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @param string $pluginName Plugin name
     * @param array<string, mixed> $pluginData Plugin data
     * @return list<string>|false|null Tag filter, null for all, false if cancelled
     */
    protected function promptForTags(ConsoleIo $io, string $pluginName, array $pluginData): array|null|false
    {
        /** @var list<string> $tagNames */
        $tagNames = [];
        foreach (array_keys($pluginData['assets']) as $tag) {
            if (!is_string($tag)) {
                continue;
            }

            if ($tag === Tag::STAR_REPO) {
                continue;
            }

            $tagNames[] = $tag;
        }

        if ($tagNames === []) {
            return null;
        }

        $options = [
            self::ALL_TAGS => 'All assets',
        ];

        foreach ($tagNames as $tag) {
            $assetCount = count($pluginData['assets'][$tag]);
            $options[$tag] = sprintf('%s (%d asset(s))', $tag, $assetCount);
        }

        $helper = $io->helper('Crustum/Prompts.MultiSelect');
        assert($helper instanceof MultiSelectHelper);

        $selected = $helper->run([
            'label' => "Select assets from {$pluginName}",
            'options' => $options,
            'default' => [],
            'scroll' => 15,
            'hint' => 'Space to toggle, Enter to confirm.',
        ]);

        return $this->resolveSelectedTags(is_array($selected) ? $selected : [], $tagNames);
    }
}
