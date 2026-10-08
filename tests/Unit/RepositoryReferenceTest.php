<?php declare(strict_types=1);

namespace DhGithubUpdater\Tests\Unit;

use DhGithubUpdater\Exception\GithubUpdaterException;
use DhGithubUpdater\Github\RepositoryReference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RepositoryReferenceTest extends TestCase
{
    #[DataProvider('validInputs')]
    public function testAcceptsValidInput(string $input, string $expected): void
    {
        static::assertSame($expected, RepositoryReference::fromUserInput($input)->getFullName());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validInputs(): iterable
    {
        yield 'plain url' => ['https://github.com/FriendsOfShopware/FroshTools', 'FriendsOfShopware/FroshTools'];
        yield 'trailing slash' => ['https://github.com/shopware/SwagPayPal/', 'shopware/SwagPayPal'];
        yield 'git suffix' => ['https://github.com/shopware/SwagPayPal.git', 'shopware/SwagPayPal'];
        yield 'tree url' => ['https://github.com/mollie/Shopware6/tree/master', 'mollie/Shopware6'];
        yield 'www host' => ['https://www.github.com/a-b/c.d_e', 'a-b/c.d_e'];
        yield 'short form' => ['owner/repo', 'owner/repo'];
    }

    #[DataProvider('invalidInputs')]
    public function testRejectsInvalidInput(string $input): void
    {
        $this->expectException(GithubUpdaterException::class);
        RepositoryReference::fromUserInput($input);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'empty' => [''];
        yield 'http' => ['http://github.com/owner/repo'];
        yield 'foreign host' => ['https://evil.example/github.com/owner/repo'];
        yield 'host suffix' => ['https://github.com.evil.example/owner/repo'];
        yield 'query trick' => ['https://evil.example/?u=https://github.com/owner/repo'];
        yield 'userinfo' => ['https://github.com@evil.example/owner/repo'];
        yield 'credentials' => ['https://user:pass@github.com/owner/repo'];
        yield 'port' => ['https://github.com:8443/owner/repo'];
        yield 'missing repo' => ['https://github.com/owner'];
        yield 'traversal' => ['https://github.com/owner/..'];
        yield 'dot' => ['owner/.'];
        yield 'encoded slash' => ['https://github.com/owner/re%2Fpo'];
        yield 'whitespace' => ['https://github.com/owner/repo name'];
        yield 'newline' => ["owner/repo\n"."x"];
        yield 'file scheme' => ['file:///etc/passwd'];
        yield 'invalid owner' => ['-owner/repo'];
    }

    public function testApiPathIsEncoded(): void
    {
        static::assertSame('/repos/owner/my.repo', RepositoryReference::fromUserInput('owner/my.repo')->getApiPath());
    }
}
