<?php declare(strict_types=1);

namespace DhGithubUpdater\Tests\Unit;

use DhGithubUpdater\Exception\GithubUpdaterException;
use DhGithubUpdater\Github\RepositoryReference;
use DhGithubUpdater\Security\RepositoryAllowList;
use PHPUnit\Framework\TestCase;

class RepositoryAllowListTest extends TestCase
{
    public function testEmptyListAllowsEverything(): void
    {
        $list = new RepositoryAllowList("  \n ");

        static::assertFalse($list->isRestricted());
        static::assertTrue($list->isAllowed(RepositoryReference::fromFullName('any/repo')));
    }

    public function testOwnerWildcardAndExactEntries(): void
    {
        $list = new RepositoryAllowList("FriendsOfShopware/*\nshopware/SwagPayPal, invalid entry ../x");

        static::assertTrue($list->isAllowed(RepositoryReference::fromFullName('friendsofshopware/FroshTools')));
        static::assertTrue($list->isAllowed(RepositoryReference::fromFullName('shopware/swagpaypal')));
        static::assertFalse($list->isAllowed(RepositoryReference::fromFullName('shopware/shopware')));
        static::assertFalse($list->isAllowed(RepositoryReference::fromFullName('FriendsOfShopware-evil/FroshTools')));
    }

    public function testAssertAllowedThrows(): void
    {
        $this->expectException(GithubUpdaterException::class);
        (new RepositoryAllowList('owner/repo'))->assertAllowed(RepositoryReference::fromFullName('other/repo'));
    }
}
