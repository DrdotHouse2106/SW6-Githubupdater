<?php declare(strict_types=1);

namespace DhGithubUpdater\Service;

use DhGithubUpdater\Package\PluginManifest;

final class StagedPackage
{
    public function __construct(
        public readonly string $path,
        public readonly PluginManifest $manifest,
    ) {
    }
}
