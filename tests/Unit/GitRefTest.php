<?php declare(strict_types=1);

namespace DhGithubUpdater\Tests\Unit;

use DhGithubUpdater\Exception\GithubUpdaterException;
use DhGithubUpdater\Github\GitRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GitRefTest extends TestCase
{
    #[DataProvider('names')]
    public function testNameValidation(string $name, bool $valid): void
    {
        static::assertSame($valid, GitRef::isValidName($name));
    }

    /**
     * @return iterable<array{string, bool}>
     */
    public static function names(): iterable
    {
        yield ['v1.2.3', true];
        yield ['6.6', true];
        yield ['release/6.7', true];
        yield ['1.0.0+build', true];
        yield ['../etc', false];
        yield ['a..b', false];
        yield ['/abs', false];
        yield ['trailing/', false];
        yield ['-option', false];
        yield ['with space', false];
        yield ['query?x=1', false];
        yield ['hash#x', false];
        yield ['percent%2e', false];
        yield ['branch.lock', false];
        yield ['', false];
    }

    public function testRejectsInvalidSha(): void
    {
        $this->expectException(GithubUpdaterException::class);
        new GitRef('v1.0.0', GitRef::TYPE_TAG, 'not-a-sha');
    }

    public function testRejectsInvalidType(): void
    {
        $this->expectException(GithubUpdaterException::class);
        new GitRef('v1.0.0', 'commit', str_repeat('a', 40));
    }
}
