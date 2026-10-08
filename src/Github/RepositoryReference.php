<?php declare(strict_types=1);

namespace DhGithubUpdater\Github;

use DhGithubUpdater\Exception\GithubUpdaterException;

/**
 * Streng validierte Referenz auf ein GitHub-Repository ("owner/repo").
 *
 * Es werden ausschließlich https-URLs mit dem exakten Host github.com
 * (oder www.github.com) sowie die Kurzform "owner/repo" akzeptiert.
 * Dadurch kann über die Eingabe weder ein fremder Host noch ein
 * manipulierter API-Pfad angesprochen werden.
 */
final class RepositoryReference
{
    private const OWNER_PATTERN = '/^[A-Za-z0-9](?:[A-Za-z0-9]|-(?=[A-Za-z0-9])){0,38}$/';
    private const REPO_PATTERN = '/^[A-Za-z0-9._-]{1,100}$/';

    private function __construct(
        public readonly string $owner,
        public readonly string $name,
    ) {
    }

    public static function fromUserInput(string $input): self
    {
        $input = trim($input);

        if ($input === '' || \strlen($input) > 300 || preg_match('/[\x00-\x20\x7F]/', $input)) {
            throw new GithubUpdaterException('Bitte eine gültige GitHub-Repository-URL angeben.');
        }

        if (preg_match('#^[^/:]+/[^/:]+$#', $input)) {
            [$owner, $name] = explode('/', $input, 2);

            return self::create($owner, $name);
        }

        $parts = parse_url($input);
        if ($parts === false
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || !\in_array(strtolower($parts['host'] ?? ''), ['github.com', 'www.github.com'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])
        ) {
            throw new GithubUpdaterException('Es werden nur https://github.com/<owner>/<repository>-URLs unterstützt.');
        }

        $segments = array_values(array_filter(explode('/', $parts['path'] ?? ''), static fn (string $s): bool => $s !== ''));
        if (\count($segments) < 2) {
            throw new GithubUpdaterException('Die URL muss Besitzer und Repository enthalten (https://github.com/<owner>/<repository>).');
        }

        return self::create($segments[0], $segments[1]);
    }

    public static function fromFullName(string $fullName): self
    {
        $parts = explode('/', $fullName);
        if (\count($parts) !== 2) {
            throw new GithubUpdaterException('Ungültiger Repository-Name.');
        }

        return self::create($parts[0], $parts[1]);
    }

    private static function create(string $owner, string $name): self
    {
        if (str_ends_with(strtolower($name), '.git')) {
            $name = substr($name, 0, -4);
        }

        if (!preg_match(self::OWNER_PATTERN, $owner)
            || !preg_match(self::REPO_PATTERN, $name)
            || $name === '.'
            || $name === '..'
        ) {
            throw new GithubUpdaterException('Ungültiger GitHub-Besitzer oder Repository-Name.');
        }

        return new self($owner, $name);
    }

    public function getFullName(): string
    {
        return $this->owner . '/' . $this->name;
    }

    public function getUrl(): string
    {
        return 'https://github.com/' . $this->getFullName();
    }

    /**
     * API-Pfad mit URL-kodierten Bestandteilen, z. B. "/repos/owner/repo".
     */
    public function getApiPath(): string
    {
        return '/repos/' . rawurlencode($this->owner) . '/' . rawurlencode($this->name);
    }

    public function equals(self $other): bool
    {
        return strcasecmp($this->getFullName(), $other->getFullName()) === 0;
    }
}
