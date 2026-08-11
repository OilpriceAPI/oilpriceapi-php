<?php

declare(strict_types=1);

namespace OilPriceAPI\Tests;

use OilPriceAPI\Client;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/scripts/validate-public-claims.php';

final class PublicClaimsTest extends TestCase
{
    public function testPublicSurfaceDiscoveryCoversNestedPackageFiles(): void
    {
        $root = dirname(__DIR__);
        $files = $this->publicSurfaceFiles($root);

        self::assertContains('src/RawClient.php', $files);
        self::assertContains('src/Http/CurlTransport.php', $files);
        self::assertContains('src/Exception/TransportException.php', $files);
    }

    public function testFutureComposerTextFilesAndQuotaAliasesCannotBypassDiscovery(): void
    {
        $root = sys_get_temp_dir() . '/oilpriceapi-claims-' . bin2hex(random_bytes(8));
        mkdir($root . '/docs/nested', recursive: true);
        mkdir($root . '/src/data', recursive: true);
        file_put_contents($root . '/CUSTOMER_GUIDE', "See current product facts.\n");
        file_put_contents($root . '/docs/nested/guide.md', "Includes 1,000 API requests/month.\n");
        file_put_contents($root . '/src/data/catalog.json', '{"rate": "100 requests per hour"}');
        file_put_contents($root . '/src/data/cache.pyc', "\x00\xff\x00");

        try {
            $files = oilpriceapiPublicTextFiles($root);
            self::assertContains('CUSTOMER_GUIDE', $files);
            self::assertContains('docs/nested/guide.md', $files);
            self::assertContains('src/data/catalog.json', $files);
            self::assertNotContains('src/data/cache.pyc', $files);

            $failures = oilpriceapiClaimFailures($root, $files);
            self::assertTrue($this->containsFailure($failures, 'docs/nested/guide.md', 'fixed allowance'));
            self::assertTrue($this->containsFailure($failures, 'src/data/catalog.json', 'fixed demo rate'));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testPackagedSmokeScansTheExactInstalledComposerArchive(): void
    {
        $root = dirname(__DIR__);
        $smoke = (string) file_get_contents($root . '/scripts/clean-install-smoke.sh');
        self::assertStringContainsString('validate-public-claims.php', $smoke);
        self::assertStringContainsString('vendor/oilpriceapi/oilpriceapi', $smoke);

        $composer = json_decode(
            (string) file_get_contents($root . '/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        foreach (['/.github', '/scripts', '/tests', '/vendor'] as $devOnlyPath) {
            self::assertContains($devOnlyPath, $composer['archive']['exclude']);
        }
    }

    public function testPublicSurfacesContainNoHighRiskProductClaims(): void
    {
        $root = dirname(__DIR__);
        $files = $this->publicSurfaceFiles($root);
        $forbidden = [
            'fixed catalog total' => '~\b\d+\+\s+(commodit|endpoint|api)~i',
            'fixed update cadence' => '~(updated|refresh(ed)?)\s+every\s+\d+|every\s+\d+\s+minutes~i',
            'unreviewed plan name' => '~professional\+|starter plan|scale tier~i',
            'unreviewed plan price' => '~\$\d+(\.\d+)?\s*(/|per\s+)(mo(nth)?|year)~i',
            'uptime or SLA' => '~\b\d+(\.\d+)?%\s+uptime|\bSLA\b~i',
            'price comparison' => '~bloomberg|\d+(\.\d+)?%\s+less\s+cost~i',
            'quota promise' => '~does\s+not\s+consume.{0,40}quota|\bunlimited\b~i',
            'universal catalog' => '~\ball\s+(latest\s+)?prices\b|\ball\s+commodit~i',
            'real-time claim' => '~\breal[- ]time\b~i',
            'free-tier claim' => '~\bfree\s+tier\b|\bfree\s+api\s+key\b~i',
        ];

        foreach ($files as $file) {
            $content = file_get_contents($root . '/' . $file);
            self::assertIsString($content, sprintf('Unable to read %s', $file));
            foreach ($forbidden as $label => $pattern) {
                self::assertSame(
                    0,
                    preg_match($pattern, $content),
                    sprintf('%s contains %s; link to the reviewed product contract instead', $file, $label),
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    private function publicSurfaceFiles(string $root): array
    {
        $files = ['README.md', 'CHANGELOG.md', 'composer.json'];
        foreach (['src', 'examples'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $root . '/' . $directory,
                    \FilesystemIterator::SKIP_DOTS,
                ),
            );
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($root) + 1);
                $files[] = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @param list<string> $failures
     */
    private function containsFailure(array $failures, string $file, string $label): bool
    {
        foreach ($failures as $failure) {
            if (str_contains($failure, $file) && str_contains($failure, $label)) {
                return true;
            }
        }

        return false;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $path) {
            if ($path->isDir()) {
                rmdir($path->getPathname());
            } else {
                unlink($path->getPathname());
            }
        }
        rmdir($directory);
    }

    public function testCanonicalDeveloperContractIsDiscoverable(): void
    {
        $root = dirname(__DIR__);
        $composer = json_decode(
            (string) file_get_contents($root . '/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame('oilpriceapi/oilpriceapi', $composer['name']);
        self::assertSame('>=8.1', $composer['require']['php']);
        self::assertSame('2.1.0', Client::VERSION);
        self::assertSame('https://api.oilpriceapi.com', Client::DEFAULT_BASE_URL);

        $readme = (string) file_get_contents($root . '/README.md');
        foreach ([
            'OILPRICEAPI_KEY',
            'Authorization: Token YOUR_API_KEY',
            '/v1/prices/latest?by_code=BRENT_CRUDE_USD',
            'https://api.oilpriceapi.com/product-facts.json',
        ] as $required) {
            self::assertStringContainsString($required, $readme);
        }

        $quickstart = (string) file_get_contents($root . '/examples/quickstart.php');
        foreach (['OILPRICEAPI_KEY', 'BRENT_CRUDE_USD', 'statusCode', 'retryAfter'] as $required) {
            self::assertStringContainsString($required, $quickstart);
        }
    }
}
