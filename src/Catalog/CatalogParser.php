<?php declare(strict_types=1);

namespace DhGithubUpdater\Catalog;

use DhGithubUpdater\Exception\GithubUpdaterException;
use DhGithubUpdater\Github\RepositoryReference;

/**
 * Liest und validiert die Katalogdatei (src/Resources/catalog/plugins.json).
 *
 * Der Katalog kann online aktualisiert werden und gilt daher als fremde
 * Eingabe: Jedes Feld wird geprüft, ungültige Einträge werden übersprungen.
 */
final class CatalogParser
{
    public const CATEGORIES = [
        'payment',
        'shipping',
        'search',
        'marketing',
        'storefront',
        'performance',
        'administration',
        'language',
        'developer',
        'other',
    ];

    private const MAX_ENTRIES = 500;
    private const LOCALE_PATTERN = '/^[a-z]{2}-[A-Z]{2}$/';
    private const PLUGIN_NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_]{0,99}$/';

    /**
     * @param list<string> $errors wird mit den Gründen für übersprungene Einträge befüllt
     *
     * @return list<CatalogEntry>
     */
    public static function parse(string $json, array &$errors = []): array
    {
        try {
            $data = json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new GithubUpdaterException('Der Plugin-Katalog ist kein gültiges JSON.', 502, $e);
        }

        if (!\is_array($data) || ($data['version'] ?? null) !== 1 || !\is_array($data['plugins'] ?? null)) {
            throw new GithubUpdaterException('Der Plugin-Katalog hat ein unbekanntes Format.', 502);
        }

        $entries = [];
        $seen = [];
        foreach (\array_slice(array_values($data['plugins']), 0, self::MAX_ENTRIES) as $index => $item) {
            try {
                $entry = self::parseEntry($item);
            } catch (GithubUpdaterException $e) {
                $errors[] = \sprintf('Eintrag %d: %s', $index + 1, $e->getMessage());

                continue;
            }

            $key = strtolower($entry->pluginName);
            if (isset($seen[$key])) {
                $errors[] = \sprintf('Eintrag %d: Plugin "%s" ist doppelt.', $index + 1, $entry->pluginName);

                continue;
            }
            $seen[$key] = true;
            $entries[] = $entry;
        }

        return $entries;
    }

    private static function parseEntry(mixed $item): CatalogEntry
    {
        if (!\is_array($item)) {
            throw new GithubUpdaterException('kein Objekt');
        }

        $repository = RepositoryReference::fromFullName(self::text($item, 'repository', 140));

        $pluginName = self::text($item, 'pluginName', 100);
        if (!preg_match(self::PLUGIN_NAME_PATTERN, $pluginName)) {
            throw new GithubUpdaterException('"pluginName" ist ungültig');
        }

        $category = self::text($item, 'category', 30);
        if (!\in_array($category, self::CATEGORIES, true)) {
            throw new GithubUpdaterException(\sprintf('unbekannte Kategorie "%s"', $category));
        }

        $license = isset($item['license']) ? self::text($item, 'license', 40) : null;

        $description = [];
        foreach (\is_array($item['description'] ?? null) ? $item['description'] : [] as $locale => $text) {
            if (\is_string($locale) && preg_match(self::LOCALE_PATTERN, $locale) && \is_string($text) && trim($text) !== '' && mb_strlen($text) <= 400) {
                $description[$locale] = trim($text);
            }
        }
        if (!isset($description['de-DE']) && !isset($description['en-GB'])) {
            throw new GithubUpdaterException('"description" benötigt mindestens "de-DE" oder "en-GB"');
        }

        // Frühere Namen nach einer Umbenennung auf GitHub – ungültige werden still übersprungen
        $former = [];
        foreach (\is_array($item['formerRepositories'] ?? null) ? \array_slice($item['formerRepositories'], 0, 10) : [] as $name) {
            if (!\is_string($name)) {
                continue;
            }
            try {
                $former[] = RepositoryReference::fromFullName($name);
            } catch (GithubUpdaterException) {
            }
        }

        return new CatalogEntry(
            $repository,
            $pluginName,
            self::text($item, 'name', 80),
            self::text($item, 'vendor', 80),
            $category,
            $license,
            $description,
            $former,
        );
    }

    /**
     * @param array<mixed> $item
     */
    private static function text(array $item, string $key, int $maxLength): string
    {
        $value = $item[$key] ?? null;
        if (!\is_string($value) || trim($value) === '' || mb_strlen($value) > $maxLength || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new GithubUpdaterException(\sprintf('"%s" fehlt oder ist ungültig', $key));
        }

        return trim($value);
    }
}
