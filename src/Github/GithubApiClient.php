<?php declare(strict_types=1);

namespace DhGithubUpdater\Github;

use DhGithubUpdater\Exception\GithubUpdaterException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Minimaler, abgesicherter Client für die GitHub-REST-API.
 *
 * - Es werden nur fest definierte Hosts angesprochen (auch bei Weiterleitungen).
 * - Das optionale Token wird ausschließlich an api.github.com gesendet.
 * - Antwortgrößen werden begrenzt, Downloads werden gestreamt.
 */
class GithubApiClient
{
    private const API_BASE = 'https://api.github.com';
    private const API_HOST = 'api.github.com';
    private const ALLOWED_HOSTS = [
        'api.github.com',
        'codeload.github.com',
        'github.com',
        'objects.githubusercontent.com',
        'raw.githubusercontent.com',
    ];
    private const MAX_REDIRECTS = 5;
    private const MAX_JSON_BYTES = 5 * 1024 * 1024;
    private const MAX_COMPOSER_JSON_BYTES = 512 * 1024;

    private readonly HttpClientInterface $httpClient;

    public function __construct(?HttpClientInterface $httpClient = null)
    {
        $this->httpClient = $httpClient ?? HttpClient::create();
    }

    /**
     * @return list<GitRef>
     */
    public function listTags(RepositoryReference $repository, ?string $token): array
    {
        $data = $this->getJson($repository->getApiPath() . '/tags?per_page=100', $token);

        $refs = [];
        foreach ($data as $tag) {
            $name = \is_array($tag) ? ($tag['name'] ?? null) : null;
            $sha = \is_array($tag) ? ($tag['commit']['sha'] ?? null) : null;
            if (\is_string($name) && \is_string($sha) && GitRef::isValidName($name) && GitRef::isValidSha($sha)) {
                $refs[] = new GitRef($name, GitRef::TYPE_TAG, $sha);
            }
        }

        return $refs;
    }

    /**
     * @return list<GitRef>
     */
    public function listBranches(RepositoryReference $repository, ?string $token): array
    {
        $data = $this->getJson($repository->getApiPath() . '/branches?per_page=100', $token);

        $refs = [];
        foreach ($data as $branch) {
            $name = \is_array($branch) ? ($branch['name'] ?? null) : null;
            $sha = \is_array($branch) ? ($branch['commit']['sha'] ?? null) : null;
            if (\is_string($name) && \is_string($sha) && GitRef::isValidName($name) && GitRef::isValidSha($sha)) {
                $refs[] = new GitRef($name, GitRef::TYPE_BRANCH, $sha);
            }
        }

        return $refs;
    }

    public function getDefaultBranch(RepositoryReference $repository, ?string $token): ?string
    {
        $data = $this->getJson($repository->getApiPath(), $token);
        $branch = $data['default_branch'] ?? null;

        return \is_string($branch) && GitRef::isValidName($branch) ? $branch : null;
    }

    /**
     * Liefert die composer.json eines bestimmten Commits oder null, wenn sie fehlt.
     */
    public function getComposerJson(RepositoryReference $repository, string $commitSha, ?string $token): ?string
    {
        if (!GitRef::isValidSha($commitSha)) {
            throw new GithubUpdaterException('Ungültige Commit-ID.');
        }

        $response = $this->request(
            self::API_BASE . $repository->getApiPath() . '/contents/composer.json?ref=' . $commitSha,
            $token,
            ['Accept' => 'application/vnd.github.raw+json'],
        );

        $status = $response->getStatusCode();
        if ($status === 404) {
            return null;
        }
        $this->assertSuccessful($status);

        return $this->readLimited($response, self::MAX_COMPOSER_JSON_BYTES);
    }

    /**
     * Lädt das ZIP-Archiv eines Commits herunter und schreibt es nach $targetFile.
     */
    public function downloadZipball(RepositoryReference $repository, string $commitSha, string $targetFile, int $maxBytes, ?string $token): void
    {
        if (!GitRef::isValidSha($commitSha)) {
            throw new GithubUpdaterException('Ungültige Commit-ID.');
        }

        $response = $this->request(self::API_BASE . $repository->getApiPath() . '/zipball/' . $commitSha, $token, [], 300);
        $this->assertSuccessful($response->getStatusCode());

        $length = $response->getHeaders(false)['content-length'][0] ?? null;
        if ($length !== null && (int) $length > $maxBytes) {
            throw new GithubUpdaterException('Das Archiv überschreitet die erlaubte Downloadgröße.');
        }

        $handle = fopen($targetFile, 'wb');
        if ($handle === false) {
            throw new GithubUpdaterException('Temporäre Datei konnte nicht angelegt werden.', 500);
        }

        $written = 0;
        try {
            foreach ($this->httpClient->stream($response) as $chunk) {
                $content = $chunk->getContent();
                $written += \strlen($content);
                if ($written > $maxBytes) {
                    $response->cancel();

                    throw new GithubUpdaterException('Das Archiv überschreitet die erlaubte Downloadgröße.');
                }
                fwrite($handle, $content);
            }
        } catch (HttpClientException $e) {
            throw new GithubUpdaterException('Download von GitHub fehlgeschlagen.', 502, $e);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array<mixed>
     */
    private function getJson(string $path, ?string $token): array
    {
        $response = $this->request(self::API_BASE . $path, $token, ['Accept' => 'application/vnd.github+json']);
        $status = $response->getStatusCode();

        if ($status === 404) {
            throw new GithubUpdaterException('Repository nicht gefunden oder kein Zugriff. Für private Repositories muss ein GitHub-Token mit Lesezugriff hinterlegt sein.', 404);
        }
        $this->assertSuccessful($status);

        try {
            $data = json_decode($this->readLimited($response, self::MAX_JSON_BYTES), true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new GithubUpdaterException('Ungültige Antwort von GitHub.', 502, $e);
        }

        if (!\is_array($data)) {
            throw new GithubUpdaterException('Ungültige Antwort von GitHub.', 502);
        }

        return $data;
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $url, ?string $token, array $headers = [], int $maxDuration = 30): ResponseInterface
    {
        for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; ++$redirects) {
            $host = $this->assertAllowedUrl($url);

            $requestHeaders = $headers + [
                'User-Agent' => 'DhGithubUpdater (Shopware 6)',
                'X-GitHub-Api-Version' => '2022-11-28',
            ];
            if ($token !== null && $token !== '' && $host === self::API_HOST) {
                $requestHeaders['Authorization'] = 'Bearer ' . $token;
            }

            try {
                $response = $this->httpClient->request('GET', $url, [
                    'headers' => $requestHeaders,
                    'max_redirects' => 0,
                    'timeout' => 15,
                    'max_duration' => $maxDuration,
                    'buffer' => false,
                ]);
                $status = $response->getStatusCode();
            } catch (HttpClientException $e) {
                throw new GithubUpdaterException('GitHub ist nicht erreichbar.', 502, $e);
            }

            if (!\in_array($status, [301, 302, 303, 307, 308], true)) {
                return $response;
            }

            $location = $response->getHeaders(false)['location'][0] ?? null;
            $response->cancel();
            if (!\is_string($location) || $location === '') {
                throw new GithubUpdaterException('Ungültige Weiterleitung von GitHub.', 502);
            }
            $url = $this->resolveLocation($url, $location);
        }

        throw new GithubUpdaterException('Zu viele Weiterleitungen von GitHub.', 502);
    }

    private function assertAllowedUrl(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');

        if ($parts === false
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user'])
            || isset($parts['port'])
            || !\in_array($host, self::ALLOWED_HOSTS, true)
        ) {
            throw new GithubUpdaterException('Weiterleitung auf einen nicht erlaubten Host wurde blockiert.', 502);
        }

        return $host;
    }

    private function resolveLocation(string $currentUrl, string $location): string
    {
        if (str_starts_with($location, 'https://')) {
            return $location;
        }

        if (str_starts_with($location, '/') && !str_starts_with($location, '//')) {
            $parts = parse_url($currentUrl);

            return 'https://' . ($parts['host'] ?? '') . $location;
        }

        throw new GithubUpdaterException('Ungültige Weiterleitung von GitHub.', 502);
    }

    private function assertSuccessful(int $status): void
    {
        if ($status === 401) {
            throw new GithubUpdaterException('Das hinterlegte GitHub-Token ist ungültig oder abgelaufen.', 502);
        }

        if ($status === 403 || $status === 429) {
            throw new GithubUpdaterException('GitHub-API-Limit erreicht oder Zugriff verweigert. Bitte später erneut versuchen oder ein GitHub-Token hinterlegen.', 502);
        }

        if ($status < 200 || $status >= 300) {
            throw new GithubUpdaterException(\sprintf('GitHub antwortete mit HTTP %d.', $status), 502);
        }
    }

    private function readLimited(ResponseInterface $response, int $maxBytes): string
    {
        $buffer = '';
        try {
            foreach ($this->httpClient->stream($response) as $chunk) {
                $buffer .= $chunk->getContent();
                if (\strlen($buffer) > $maxBytes) {
                    $response->cancel();

                    throw new GithubUpdaterException('Antwort von GitHub ist zu groß.', 502);
                }
            }
        } catch (HttpClientException $e) {
            throw new GithubUpdaterException('Antwort von GitHub konnte nicht gelesen werden.', 502, $e);
        }

        return $buffer;
    }
}
