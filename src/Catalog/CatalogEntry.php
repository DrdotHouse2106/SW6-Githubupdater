<?php declare(strict_types=1);

namespace DhGithubUpdater\Catalog;

use DhGithubUpdater\Github\RepositoryReference;

/**
 * Ein Eintrag im Plugin-Katalog.
 */
final class CatalogEntry
{
    /**
     * @param array<string, string> $description Sprache (z. B. "de-DE") => Text
     */
    public function __construct(
        public readonly RepositoryReference $repository,
        public readonly string $pluginName,
        public readonly string $name,
        public readonly string $vendor,
        public readonly string $category,
        public readonly ?string $license,
        public readonly array $description,
    ) {
    }

    /**
     * @return array{repository: string, repositoryUrl: string, pluginName: string, name: string, vendor: string, category: string, license: ?string, description: array<string, string>}
     */
    public function toArray(): array
    {
        return [
            'repository' => $this->repository->getFullName(),
            'repositoryUrl' => $this->repository->getUrl(),
            'pluginName' => $this->pluginName,
            'name' => $this->name,
            'vendor' => $this->vendor,
            'category' => $this->category,
            'license' => $this->license,
            'description' => $this->description,
        ];
    }
}
