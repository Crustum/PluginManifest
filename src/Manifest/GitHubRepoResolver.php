<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Manifest;

use Cake\Http\Client;
use Exception;

/**
 * Helper class for getting GitHub repository information from Packagist
 */
class GitHubRepoResolver
{
    /**
     * Get GitHub repository vendor/repo from plugin path
     *
     * @param string $pluginPath Plugin directory path
     * @return string|null GitHub repo in vendor/repo format or null
     */
    public static function getFromPluginPath(string $pluginPath): ?string
    {
        $composerJsonPath = $pluginPath . DS . 'composer.json';

        if (!file_exists($composerJsonPath)) {
            return null;
        }

        $composerJsonContent = file_get_contents($composerJsonPath);
        if ($composerJsonContent === false) {
            return null;
        }

        $composerJson = json_decode($composerJsonContent, true);

        if (!isset($composerJson['name'])) {
            return null;
        }

        return static::getFromPackageName($composerJson['name']);
    }

    /**
     * Get GitHub repository vendor/repo from Packagist package name
     *
     * @param string $packageName Composer package name (e.g., 'skie/plugin-manifest')
     * @return string|null GitHub repo in vendor/repo format or null
     */
    public static function getFromPackageName(string $packageName): ?string
    {
        $packagistUrl = "https://packagist.org/packages/{$packageName}.json";

        $client = new Client([
            'timeout' => 5,
            'headers' => [
                'User-Agent' => 'CakePHP-PluginManifest/1.0',
            ],
        ]);

        try {
            $response = $client->get($packagistUrl);
        } catch (Exception $e) {
            return null;
        }

        if (!$response->isOk()) {
            return null;
        }

        $packageData = json_decode($response->getStringBody(), true);

        if (!isset($packageData['package']['repository'])) {
            return null;
        }

        $repositoryUrl = $packageData['package']['repository'];

        if (is_string($repositoryUrl)) {
            return static::extractGitHubRepo($repositoryUrl);
        }

        if (is_array($repositoryUrl) && isset($repositoryUrl['url'])) {
            return static::extractGitHubRepo($repositoryUrl['url']);
        }

        return null;
    }

    /**
     * Extract vendor/repo from GitHub URL
     *
     * @param string $url GitHub repository URL
     * @return string|null Vendor/repo format or null
     */
    protected static function extractGitHubRepo(string $url): ?string
    {
        if (strpos($url, 'github.com') === false) {
            return null;
        }

        $parsed = parse_url($url);

        if (!isset($parsed['path'])) {
            return null;
        }

        $path = trim($parsed['path'], '/');

        if (preg_match('#^([^/]+/[^/]+?)(?:\.git)?$#', $path, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
