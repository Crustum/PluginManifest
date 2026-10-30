<?php
declare(strict_types=1);

namespace Crustum\PluginManifest\Manifest;

/**
 * Result object for install operations
 *
 * Represents the outcome of an asset installation operation.
 */
class InstallResult
{
    /**
     * @var array<\Crustum\PluginManifest\Manifest\InstallResult>|null
     */
    protected ?array $batchResults = null;

    /**
     * Constructor
     *
     * @param bool $success Whether the operation succeeded
     * @param string $source Source file/content identifier
     * @param string $destination Destination file path
     * @param string $status Status: 'installed', 'skipped', 'updated', 'error', 'batch-installed'
     * @param string|null $message Optional message
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $source,
        public readonly string $destination,
        public readonly string $status,
        public readonly ?string $message = null,
    ) {
    }

    /**
     * Get the batch results
     *
     * @return array<\Crustum\PluginManifest\Manifest\InstallResult>|null Batch results
     */
    public function getBatchResults(): ?array
    {
        return $this->batchResults;
    }

    /**
     * Set the batch results
     *
     * @param array<\Crustum\PluginManifest\Manifest\InstallResult> $batchResults Batch results
     * @return void
     */
    public function setBatchResults(array $batchResults): void
    {
        $this->batchResults = $batchResults;
    }

    /**
     * Whether this result should be tracked in the registry.
     *
     * Successful installs are always tracked. Skipped operations are
     * tracked when their destination file exists: the asset is present
     * on disk (e.g. a migration copied earlier or by hand), so a second
     * run converges instead of reporting it missing forever.
     *
     * @return bool
     */
    public function isRecordable(): bool
    {
        if ($this->success) {
            return true;
        }

        return $this->status === 'skipped'
            && $this->destination !== ''
            && file_exists($this->destination);
    }
}
