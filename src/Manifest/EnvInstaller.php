<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Manifest;

/**
 * Service for installing environment variables to .env file
 *
 * This service appends environment variables to .env file at the variable level,
 * not file level. It parses existing .env file and only adds vars that don't exist.
 */
class EnvInstaller
{
    /**
     * Maximum allowed .env file size (1MB)
     */
    protected const MAX_ENV_SIZE = 1048576;

    /**
     * Append environment variables to .env file
     *
     * Only adds variables that don't already exist in the file.
     * Parses existing .env content and skips variables that are already set.
     *
     * @param string $filePath Path to .env file
     * @param array<string, string> $envVars Environment variables to add
     * @param string|null $comment Optional comment to add before vars
     * @param bool $dryRun Whether to perform dry run (no actual changes)
     * @return \Crustum\PluginManifest\Manifest\InstallResult
     */
    public function appendVars(
        string $filePath,
        array $envVars,
        ?string $comment = null,
        bool $dryRun = false,
    ): InstallResult {
        $fileExists = file_exists($filePath);

        if (empty($envVars)) {
            return new InstallResult(true, 'env_vars', $filePath, 'skipped', 'Added 0 variable(s)');
        }

        if (!$fileExists && !$dryRun) {
            file_put_contents($filePath, '');
        }

        if (filesize($filePath) > static::MAX_ENV_SIZE) {
            return new InstallResult(false, 'env_vars', $filePath, 'error', 'Env file too large (>1MB), manual review required');
        }

        $existingContent = '';
        if ($fileExists) {
            $content = file_get_contents($filePath);
            $existingContent = $content !== false ? $content : '';
        }

        $existingVars = $this->parseEnvVars($existingContent);

        $newVars = [];
        foreach ($envVars as $key => $value) {
            if (!isset($existingVars[$key])) {
                $newVars[$key] = $value;
            }
        }

        if (empty($newVars)) {
            return new InstallResult(false, 'env_vars', $filePath, 'skipped', 'All environment variables already exist');
        }

        $trimmedContent = rtrim($existingContent);
        $newContent = $trimmedContent . "\n\n";
        if ($comment) {
            $newContent .= $comment . "\n";
        }

        foreach ($newVars as $key => $value) {
            $newContent .= "{$key}={$value}\n";
        }

        if (!$dryRun) {
            file_put_contents($filePath, $newContent);
        }

        $skippedCount = count($envVars) - count($newVars);
        $message = 'Added ' . count($newVars) . ' variable(s)';
        if ($skippedCount > 0) {
            $message .= ', skipped ' . $skippedCount;
        }

        return new InstallResult(true, 'env_vars', $filePath, $dryRun ? 'would-add' : 'added', $message);
    }

    /**
     * Parse environment variables from .env file content
     *
     * @param string $content File content
     * @return array<string, string>
     */
    protected function parseEnvVars(string $content): array
    {
        $vars = [];
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);

            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }

            if (strpos($line, '=') === false) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);

            if (!empty($key)) {
                $vars[$key] = trim($value);
            }
        }

        return $vars;
    }
}
