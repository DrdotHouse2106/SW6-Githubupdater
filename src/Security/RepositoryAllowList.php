<?php declare(strict_types=1);

namespace DhGithubUpdater\Security;

use DhGithubUpdater\Exception\GithubUpdaterException;
use DhGithubUpdater\Github\RepositoryReference;

/**
 * Optionale Positivliste erlaubter Repositories.
 *
 * Ein Eintrag pro Zeile (oder durch Komma getrennt):
 *   - "owner/repo"  erlaubt genau dieses Repository
 *   - "owner/*"     erlaubt alle Repositories dieses Besitzers
 * Ist die Liste leer, sind alle öffentlichen Repositories erlaubt.
 */
final class RepositoryAllowList
{
    /**
     * @var list<string>
     */
    private readonly array $entries;

    public function __construct(string $rawList)
    {
        $entries = [];
        foreach (preg_split('/[\s,;]+/', $rawList) ?: [] as $entry) {
            $entry = strtolower(trim($entry));
            if ($entry !== '' && preg_match('#^[a-z0-9-]+/(\*|[a-z0-9._-]+)$#', $entry)) {
                $entries[] = $entry;
            }
        }

        $this->entries = $entries;
    }

    public function isRestricted(): bool
    {
        return $this->entries !== [];
    }

    public function isAllowed(RepositoryReference $repository): bool
    {
        if (!$this->isRestricted()) {
            return true;
        }

        $owner = strtolower($repository->owner);
        $fullName = strtolower($repository->getFullName());

        foreach ($this->entries as $entry) {
            if ($entry === $fullName || $entry === $owner . '/*') {
                return true;
            }
        }

        return false;
    }

    public function assertAllowed(RepositoryReference $repository): void
    {
        if (!$this->isAllowed($repository)) {
            throw new GithubUpdaterException(\sprintf(
                'Das Repository "%s" ist nicht in der Liste der erlaubten Repositories (Plugin-Konfiguration).',
                $repository->getFullName(),
            ), 403);
        }
    }
}
