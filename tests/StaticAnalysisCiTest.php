<?php

declare(strict_types=1);

namespace OilPriceAPI\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Static analysis is only worth having if it actually runs on every change,
 * and only worth trusting if it is not silenced.
 *
 * A phpstan.neon in the repo proves nothing on its own: the file existing is
 * not the same as the job running, and a baseline turns a failing check into
 * a green one without fixing anything. These assertions pin all three - the
 * tool is a dev dependency, CI invokes it on pull requests, and the config
 * carries a real level with no baseline.
 */
final class StaticAnalysisCiTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__);
    }

    /**
     * @return array<string, mixed>
     */
    private static function composer(): array
    {
        $decoded = json_decode(
            (string) file_get_contents(self::root() . '/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testPhpstanIsADevDependency(): void
    {
        $composer = self::composer();

        self::assertIsArray($composer['require-dev'] ?? null);
        self::assertArrayHasKey(
            'phpstan/phpstan',
            $composer['require-dev'],
            'phpstan/phpstan must be a dev dependency so `composer install` provides it.',
        );
    }

    public function testComposerExposesAPhpstanScript(): void
    {
        $composer = self::composer();
        $scripts = $composer['scripts'] ?? null;

        self::assertIsArray($scripts);
        self::assertArrayHasKey('phpstan', $scripts, 'composer.json needs a "phpstan" script.');
    }

    public function testPhpstanConfigTargetsSrcAtAHonestLevelWithNoBaseline(): void
    {
        $configPath = self::root() . '/phpstan.neon';
        self::assertFileExists($configPath);

        $config = (string) file_get_contents($configPath);

        self::assertMatchesRegularExpression(
            '~^\s*level:\s*(9|max)\s*$~m',
            $config,
            'phpstan.neon must declare the level it actually enforces.',
        );
        self::assertMatchesRegularExpression('~^\s*-\s*src\s*$~m', $config, 'phpstan.neon must analyse src/.');

        // A baseline is the documented way to ship a green check over real
        // errors. If one is ever introduced, this test is the place to argue
        // for it - not a silent file.
        self::assertStringNotContainsString('baseline', $config);
        self::assertFileDoesNotExist(self::root() . '/phpstan-baseline.neon');
    }

    public function testCiRunsStaticAnalysisOnPullRequests(): void
    {
        $workflow = (string) file_get_contents(self::root() . '/.github/workflows/test.yml');

        self::assertStringContainsString('pull_request', $workflow);
        self::assertMatchesRegularExpression(
            '~(vendor/bin/phpstan|composer (run(-script)? )?phpstan)~',
            $workflow,
            'The test workflow must invoke PHPStan, or the config is decoration.',
        );
    }

    public function testPhpstanConfigIsNotShippedInTheComposerArchive(): void
    {
        $composer = self::composer();
        $exclude = $composer['archive']['exclude'] ?? null;

        self::assertIsArray($exclude);
        self::assertContains('/phpstan.neon', $exclude, 'Dev tooling config must not ship to Packagist.');

        $attributes = (string) file_get_contents(self::root() . '/.gitattributes');
        self::assertStringContainsString('/phpstan.neon export-ignore', $attributes);
    }
}
