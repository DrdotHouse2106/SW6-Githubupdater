<?php declare(strict_types=1);

namespace DhGithubUpdater\Tests\Unit;

use DhGithubUpdater\Exception\GithubUpdaterException;
use DhGithubUpdater\Package\SafeZipExtractor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

class SafeZipExtractorTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/dh-ghu-test-' . bin2hex(random_bytes(6));
        mkdir($this->workDir . '/target', 0777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->workDir);
    }

    public function testExtractsAndStripsRootFolder(): void
    {
        $zip = $this->createZip([
            'owner-repo-abc/' => null,
            'owner-repo-abc/composer.json' => '{}',
            'owner-repo-abc/src/Plugin.php' => '<?php',
        ]);

        (new SafeZipExtractor())->extract($zip, $this->workDir . '/target');

        static::assertFileExists($this->workDir . '/target/composer.json');
        static::assertFileExists($this->workDir . '/target/src/Plugin.php');
    }

    public function testRejectsPathTraversal(): void
    {
        $zip = $this->createZip([
            'owner-repo-abc/composer.json' => '{}',
            'owner-repo-abc/../../evil.php' => '<?php',
        ]);

        $this->expectException(GithubUpdaterException::class);
        try {
            (new SafeZipExtractor())->extract($zip, $this->workDir . '/target');
        } finally {
            static::assertFileDoesNotExist($this->workDir . '/evil.php');
        }
    }

    public function testRejectsAbsolutePath(): void
    {
        $zip = $this->createZip(['/tmp/evil.php' => '<?php']);

        $this->expectException(GithubUpdaterException::class);
        (new SafeZipExtractor())->extract($zip, $this->workDir . '/target');
    }

    public function testRejectsBackslashPath(): void
    {
        $zip = $this->createZip(['root/..\\..\\evil.php' => '<?php']);

        $this->expectException(GithubUpdaterException::class);
        (new SafeZipExtractor())->extract($zip, $this->workDir . '/target');
    }

    public function testRejectsSymlinks(): void
    {
        $file = $this->workDir . '/symlink.zip';
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::CREATE);
        $zip->addFromString('root/composer.json', '{}');
        $zip->addFromString('root/link', '/etc/passwd');
        $zip->setExternalAttributesName('root/link', \ZipArchive::OPSYS_UNIX, (0120777 << 16));
        $zip->close();

        $this->expectException(GithubUpdaterException::class);
        $this->expectExceptionMessageMatches('/symbolischen Link/');
        (new SafeZipExtractor())->extract($file, $this->workDir . '/target');
    }

    public function testRejectsMultipleRoots(): void
    {
        $zip = $this->createZip(['a/composer.json' => '{}', 'b/evil.php' => '<?php']);

        $this->expectException(GithubUpdaterException::class);
        (new SafeZipExtractor())->extract($zip, $this->workDir . '/target');
    }

    public function testRejectsZipBomb(): void
    {
        $zip = $this->createZip(['root/big.bin' => str_repeat('A', 4096)]);

        $this->expectException(GithubUpdaterException::class);
        (new SafeZipExtractor(100, 1024))->extract($zip, $this->workDir . '/target');
    }

    public function testRejectsTooManyFiles(): void
    {
        $zip = $this->createZip(['root/a' => '1', 'root/b' => '2', 'root/c' => '3']);

        $this->expectException(GithubUpdaterException::class);
        (new SafeZipExtractor(2))->extract($zip, $this->workDir . '/target');
    }

    public function testRequiresEmptyTarget(): void
    {
        touch($this->workDir . '/target/existing');
        $zip = $this->createZip(['root/a' => '1']);

        $this->expectException(GithubUpdaterException::class);
        (new SafeZipExtractor())->extract($zip, $this->workDir . '/target');
    }

    public function testRejectsInvalidArchive(): void
    {
        file_put_contents($this->workDir . '/broken.zip', 'no zip');

        $this->expectException(GithubUpdaterException::class);
        (new SafeZipExtractor())->extract($this->workDir . '/broken.zip', $this->workDir . '/target');
    }

    /**
     * @param array<string, string|null> $entries
     */
    private function createZip(array $entries): string
    {
        $file = $this->workDir . '/' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::CREATE);
        foreach ($entries as $name => $content) {
            if ($content === null) {
                $zip->addEmptyDir(rtrim($name, '/'));
            } else {
                $zip->addFromString($name, $content);
            }
        }
        $zip->close();

        return $file;
    }
}
