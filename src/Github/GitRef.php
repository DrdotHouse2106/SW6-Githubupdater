<?php declare(strict_types=1);

namespace DhGithubUpdater\Github;

use DhGithubUpdater\Exception\GithubUpdaterException;

/**
 * Ein Tag oder Branch, wie er von der GitHub-API geliefert wurde.
 */
final class GitRef
{
    public const TYPE_TAG = 'tag';
    public const TYPE_BRANCH = 'branch';

    private const NAME_PATTERN = '#^(?!-)(?!.*\.\.)(?!.*//)(?!/)(?!.*/$)[A-Za-z0-9._/+-]{1,200}$#';
    private const SHA_PATTERN = '/^[0-9a-f]{40}$/';

    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $commitSha,
    ) {
        if (!self::isValidName($name)) {
            throw new GithubUpdaterException('Ungültiger Tag- oder Branch-Name.');
        }

        if (!\in_array($type, [self::TYPE_TAG, self::TYPE_BRANCH], true)) {
            throw new GithubUpdaterException('Ungültiger Referenztyp.');
        }

        if (!self::isValidSha($commitSha)) {
            throw new GithubUpdaterException('Ungültige Commit-ID.');
        }
    }

    public static function isValidName(string $name): bool
    {
        return (bool) preg_match(self::NAME_PATTERN, $name) && !str_ends_with($name, '.lock');
    }

    public static function isValidSha(string $sha): bool
    {
        return (bool) preg_match(self::SHA_PATTERN, $sha);
    }
}
