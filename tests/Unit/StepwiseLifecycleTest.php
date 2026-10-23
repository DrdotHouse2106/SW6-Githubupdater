<?php declare(strict_types=1);

namespace DhGithubUpdater\Tests\Unit;

use DhGithubUpdater\Service\StepwiseLifecycle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

class StepwiseLifecycleTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dh-ghu-stepwise-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/custom/plugins/WithStorefront/src/Resources/app/storefront/src', 0777, true);
        mkdir($this->dir . '/custom/plugins/WithTheme/src/Resources', 0777, true);
        touch($this->dir . '/custom/plugins/WithTheme/src/Resources/theme.json');
        mkdir($this->dir . '/custom/plugins/AdminOnly/src/Resources/app/administration/src', 0777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testDetectsStorefrontFilesOnDisk(): void
    {
        static::assertTrue(StepwiseLifecycle::hasStorefrontFiles('custom/plugins/WithStorefront', $this->dir));
        static::assertTrue(StepwiseLifecycle::hasStorefrontFiles('custom/plugins/WithTheme/', $this->dir));
        static::assertTrue(StepwiseLifecycle::hasStorefrontFiles($this->dir . '/custom/plugins/WithStorefront', '/anderswo'));
        static::assertFalse(StepwiseLifecycle::hasStorefrontFiles('custom/plugins/AdminOnly', $this->dir));
        static::assertFalse(StepwiseLifecycle::hasStorefrontFiles('custom/plugins/Missing', $this->dir));
        static::assertFalse(StepwiseLifecycle::hasStorefrontFiles('', $this->dir));
    }
}
