<?php

declare(strict_types=1);

namespace OilPriceAPI\Tests;

use OilPriceAPI\Client;
use PHPUnit\Framework\TestCase;

/**
 * The advertised version has to match what actually shipped.
 *
 * #18 narrowed `Price::fromArray()` - a public, documented, total function -
 * into a partial one that throws. That is a major version, and it went out
 * behind `Client::VERSION = '2.1.2'` and a changelog entry with no version
 * heading at all. An integrator reading either would conclude nothing in their
 * code could break.
 */
final class VersioningTest extends TestCase
{
    private const LAST_BC_COMPATIBLE_MAJOR = 2;

    public function testVersionIsSemver(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+$/',
            Client::VERSION,
            'Client::VERSION must be a plain MAJOR.MINOR.PATCH string.',
        );
    }

    /**
     * `Price::fromArray()` throws for input the 2.x line accepted. That is not
     * a patch.
     */
    public function testVersionRecordsTheBreakingChangeAsAMajor(): void
    {
        [$major] = array_map('intval', explode('.', Client::VERSION));

        $this->assertGreaterThan(
            self::LAST_BC_COMPATIBLE_MAJOR,
            $major,
            sprintf(
                'Price::fromArray() went from total to partial, so %s is not a valid version '
                . 'for this line: a caller reading it would expect no breakage.',
                Client::VERSION,
            ),
        );
    }

    /**
     * The topmost changelog entry must name a version. "## Unreleased" tells a
     * reader nothing about whether upgrading is safe.
     */
    public function testTopmostChangelogEntryCarriesAVersionHeading(): void
    {
        $heading = self::firstChangelogHeading();

        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+/',
            $heading,
            sprintf('The topmost CHANGELOG entry is "## %s" and names no version.', $heading),
        );
    }

    public function testTopmostChangelogEntryMatchesTheShippedVersion(): void
    {
        $this->assertStringStartsWith(
            Client::VERSION,
            self::firstChangelogHeading(),
            'The CHANGELOG and Client::VERSION disagree about what is shipping.',
        );
    }

    /**
     * The break has to be written down in words a caller can act on: what
     * throws now that did not before.
     */
    public function testChangelogDocumentsTheBreakingChange(): void
    {
        $entry = self::firstChangelogEntry();

        $this->assertMatchesRegularExpression(
            '/###\s+Breaking changes/i',
            $entry,
            'The major entry has no "### Breaking changes" section.',
        );
        $this->assertStringContainsString(
            'Price::fromArray',
            $entry,
            'The breaking-change note does not name the method that broke.',
        );
        $this->assertMatchesRegularExpression(
            '/ApiException/',
            $entry,
            'The breaking-change note does not say what is thrown.',
        );
    }

    /**
     * The version a customer sees in our access logs must be the version we
     * think we shipped.
     */
    public function testUserAgentCarriesTheSameVersion(): void
    {
        $transport = new MockTransport();
        $transport->queue(200, [
            'status' => 'success',
            'data' => ['code' => 'BRENT_CRUDE_USD', 'price' => 71.8, 'currency' => 'USD'],
        ]);
        $client = new Client('fixture_key_NOT_REAL_0123456789', Client::DEFAULT_BASE_URL, 10.0, 0, $transport);

        $client->latest('BRENT_CRUDE_USD');

        $this->assertSame(
            'oilpriceapi-php/' . Client::VERSION,
            $transport->requests[0]['headers']['User-Agent'],
        );
    }

    private static function changelog(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/CHANGELOG.md');
    }

    private static function firstChangelogHeading(): string
    {
        preg_match('/^## (.+)$/m', self::changelog(), $matches);
        self::assertNotEmpty($matches, 'CHANGELOG.md has no "## " heading.');

        return trim($matches[1]);
    }

    private static function firstChangelogEntry(): string
    {
        $parts = preg_split('/^## /m', self::changelog());
        self::assertIsArray($parts);
        self::assertArrayHasKey(1, $parts, 'CHANGELOG.md has no entries.');

        return $parts[1];
    }
}
