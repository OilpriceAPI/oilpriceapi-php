<?php

declare(strict_types=1);

namespace OilPriceAPI\Tests;

use OilPriceAPI\Client;
use PHPUnit\Framework\TestCase;

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
