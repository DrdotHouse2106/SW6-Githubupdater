<?php declare(strict_types=1);

namespace DhGithubUpdater\Tests\Unit;

use DhGithubUpdater\Catalog\CatalogParser;
use DhGithubUpdater\Exception\GithubUpdaterException;
use PHPUnit\Framework\TestCase;

class CatalogParserTest extends TestCase
{
    /**
     * Schützt Beiträge zum Katalog: Jeder Eintrag der mitgelieferten Datei muss gültig sein.
     */
    public function testBundledCatalogIsValid(): void
    {
        $errors = [];
        $entries = CatalogParser::parse((string) file_get_contents(__DIR__ . '/../../src/Resources/catalog/plugins.json'), $errors);

        static::assertSame([], $errors);
        static::assertNotEmpty($entries);

        foreach ($entries as $entry) {
            static::assertArrayHasKey('de-DE', $entry->description, $entry->pluginName . ': deutsche Beschreibung fehlt');
            static::assertArrayHasKey('en-GB', $entry->description, $entry->pluginName . ': englische Beschreibung fehlt');
        }
    }

    public function testSkipsInvalidAndDuplicateEntries(): void
    {
        $valid = [
            'repository' => 'owner/repo',
            'pluginName' => 'MyPlugin',
            'name' => 'Mein Plugin',
            'vendor' => 'Ich',
            'category' => 'other',
            'description' => ['de-DE' => 'Text', 'xx' => 'ignoriert'],
        ];

        $errors = [];
        $entries = CatalogParser::parse((string) json_encode(['version' => 1, 'plugins' => [
            $valid,
            $valid,
            ['repository' => 'https://evil.example/x/y'] + $valid,
            ['pluginName' => '../evil'] + $valid,
            ['category' => 'unknown'] + $valid,
            ['description' => []] + $valid,
            ['name' => "Zeile\nUmbruch"] + $valid,
        ]]), $errors);

        static::assertCount(1, $entries);
        static::assertCount(6, $errors);
        static::assertSame(['de-DE' => 'Text'], $entries[0]->description);
        static::assertSame('https://github.com/owner/repo', $entries[0]->toArray()['repositoryUrl']);
    }

    public function testRejectsUnknownFormat(): void
    {
        $this->expectException(GithubUpdaterException::class);
        CatalogParser::parse('{"version": 2, "plugins": []}');
    }

    public function testReadsFormerRepositories(): void
    {
        $entries = CatalogParser::parse((string) json_encode(['version' => 1, 'plugins' => [[
            'repository' => 'owner/New-Name',
            'formerRepositories' => ['owner/Old-Name', 'kein gültiger Name', 42],
            'pluginName' => 'MyPlugin',
            'name' => 'Mein Plugin',
            'vendor' => 'Ich',
            'category' => 'other',
            'description' => ['de-DE' => 'Text'],
        ]]]));

        static::assertCount(1, $entries);
        static::assertSame(['owner/Old-Name'], $entries[0]->toArray()['formerRepositories']);
        static::assertTrue($entries[0]->isFormerRepository('OWNER/old-name'));
        static::assertFalse($entries[0]->isFormerRepository('owner/New-Name'));
    }
}
