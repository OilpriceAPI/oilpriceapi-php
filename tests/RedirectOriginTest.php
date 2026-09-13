<?php

declare(strict_types=1);

namespace OilPriceAPI\Tests;

use OilPriceAPI\Client;
use OilPriceAPI\Exception\ApiException;
use OilPriceAPI\Exception\TransportException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A redirect must not move the request - or the credential - off the
 * configured origin.
 *
 * The #17 origin guard validates the URL the SDK *sends*. It says nothing
 * about where the server then points us: `CURLOPT_FOLLOWLOCATION` let a 302
 * from the API host deliver a body from somewhere else, which the SDK decoded
 * and returned as an authoritative price. On libcurl older than 7.58.0 the
 * same hop also carries `Authorization: Token <key>` to the new host.
 *
 * Everything here drives the real `CurlTransport` against real loopback
 * servers. A mock transport cannot see this defect - it lives inside cURL.
 */
final class RedirectOriginTest extends TestCase
{
    private const KEY = 'fixture_key_NOT_REAL_0123456789';

    /** @var list<resource> */
    private array $servers = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            proc_terminate($server);
            proc_close($server);
        }
        $this->servers = [];
    }

    /**
     * @return array<string, array{int}>
     */
    public static function redirectStatuses(): array
    {
        return [
            '301 moved permanently' => [301],
            '302 found' => [302],
            '303 see other' => [303],
            '307 temporary redirect' => [307],
            '308 permanent redirect' => [308],
        ];
    }

    /**
     * The defect exactly as reported: a cross-origin 3xx from the configured
     * API host must not produce price data, and the foreign host must never
     * be contacted at all.
     */
    #[DataProvider('redirectStatuses')]
    public function testCrossOriginRedirectNeitherReturnsDataNorReachesTheForeignHost(int $status): void
    {
        $log = $this->makeLog();
        $foreignPort = $this->startForeignOrigin($log);
        $apiPort = $this->startRedirector('http://127.0.0.1:' . $foreignPort, $status);

        $client = new Client(self::KEY, 'http://127.0.0.1:' . $apiPort, 5.0, 0);

        try {
            $price = $client->latest('BRENT_CRUDE_USD');
            $this->fail(sprintf(
                'A %d to a foreign origin returned price data: code=%s price=%s',
                $status,
                is_array($price) ? 'list' : $price->code,
                is_array($price) ? 'list' : (string) $price->price,
            ));
        } catch (TransportException | ApiException $e) {
            $this->assertStringNotContainsString('PWNED', $e->getMessage());
        }

        $this->assertSame(
            '',
            trim((string) file_get_contents($log)),
            'The SDK followed the redirect and contacted the foreign origin: '
            . (string) file_get_contents($log),
        );
    }

    /**
     * The credential half, stated on its own. Even where modern libcurl would
     * strip `Authorization` across an origin change, the SDK must not depend
     * on that: `composer.json` carries no libcurl floor a host must meet.
     */
    public function testTheApiKeyNeverReachesTheRedirectTarget(): void
    {
        $log = $this->makeLog();
        $foreignPort = $this->startForeignOrigin($log);
        $apiPort = $this->startRedirector('http://127.0.0.1:' . $foreignPort, 302);

        $client = new Client(self::KEY, 'http://127.0.0.1:' . $apiPort, 5.0, 0);

        try {
            $client->latest('BRENT_CRUDE_USD');
        } catch (TransportException | ApiException) {
            // expected
        }

        $captured = (string) file_get_contents($log);
        $this->assertStringNotContainsString(self::KEY, $captured, 'The API key reached the redirect target: ' . $captured);
        $this->assertSame('', trim($captured), 'The redirect target was contacted at all: ' . $captured);
    }

    /**
     * A redirect body is not data. Even when the redirect points at the
     * configured origin, the 3xx envelope must not be decoded and returned as
     * a price.
     */
    public function testSameOriginRedirectBodyIsNotReturnedAsAPrice(): void
    {
        $apiPort = $this->freePort();
        $this->startRedirectorOnPort($apiPort, 'http://127.0.0.1:' . $apiPort, 302);

        $client = new Client(self::KEY, 'http://127.0.0.1:' . $apiPort, 5.0, 0);

        try {
            $price = $client->latest('BRENT_CRUDE_USD');
            $this->fail(sprintf(
                'A same-origin 302 body was returned as a price: code=%s',
                is_array($price) ? 'list' : $price->code,
            ));
        } catch (TransportException | ApiException $e) {
            $this->assertStringNotContainsString('REDIRECT_BODY', $e->getMessage());
        }
    }

    /**
     * The error a caller sees has to say what happened. A redirect surfacing as
     * "invalid JSON" sends an integrator hunting the wrong problem.
     */
    public function testRedirectRaisesAnErrorThatNamesTheRedirect(): void
    {
        $log = $this->makeLog();
        $foreignPort = $this->startForeignOrigin($log);
        $apiPort = $this->startRedirector('http://127.0.0.1:' . $foreignPort, 302);

        $client = new Client(self::KEY, 'http://127.0.0.1:' . $apiPort, 5.0, 0);

        try {
            $client->latest('BRENT_CRUDE_USD');
            $this->fail('Expected the redirect to raise.');
        } catch (TransportException | ApiException $e) {
            $this->assertMatchesRegularExpression(
                '/redirect/i',
                $e->getMessage(),
                'The error must name the redirect: ' . $e->getMessage(),
            );
        }
    }

    /**
     * A non-redirect response over the same real transport still works, so the
     * fix is not "reject everything".
     */
    public function testOrdinaryResponseOverTheRealTransportStillWorks(): void
    {
        $port = $this->freePort();
        $this->spawn($port, __DIR__ . '/fixtures/router.php', []);

        $client = new Client('valid-smoke-key', 'http://127.0.0.1:' . $port, 5.0, 0);
        $price = $client->latest('BRENT_CRUDE_USD');

        $this->assertSame('BRENT_CRUDE_USD', $price->code);
        $this->assertSame(71.80, $price->price);
    }

    /**
     * Regression fence on the setting itself: the runtime guard below is a
     * tripwire, not the control. The control is that the transport does not
     * follow redirects at all.
     */
    public function testTransportDoesNotEnableFollowLocation(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Http/CurlTransport.php');

        $this->assertStringNotContainsString(
            'CURLOPT_FOLLOWLOCATION => true',
            $source,
            'Re-enabling redirect following re-opens the #22 origin bypass.',
        );
        $this->assertStringContainsString('CURLOPT_FOLLOWLOCATION => false', $source);
    }

    /**
     * `composer.json` must state the libcurl floor it relies on rather than
     * accepting any version of a library whose credential-stripping behaviour
     * changed in 7.58.0.
     */
    public function testComposerDeclaresALibcurlFloor(): void
    {
        $composer = json_decode(
            (string) file_get_contents(dirname(__DIR__) . '/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertArrayHasKey('lib-curl', $composer['require']);
        $this->assertMatchesRegularExpression('/^>=7\.58/', (string) $composer['require']['lib-curl']);
    }

    private function makeLog(): string
    {
        $log = tempnam(sys_get_temp_dir(), 'opa-redirect-');
        self::assertIsString($log);
        file_put_contents($log, '');

        return $log;
    }

    private function startForeignOrigin(string $log): int
    {
        $port = $this->freePort();
        $this->spawn($port, __DIR__ . '/fixtures/foreign-origin.php', ['FOREIGN_LOG' => $log]);

        return $port;
    }

    private function startRedirector(string $target, int $status): int
    {
        $port = $this->freePort();
        $this->startRedirectorOnPort($port, $target, $status);

        return $port;
    }

    private function startRedirectorOnPort(int $port, string $target, int $status): void
    {
        $this->spawn($port, __DIR__ . '/fixtures/redirector.php', [
            'REDIRECT_TO' => $target,
            'REDIRECT_STATUS' => (string) $status,
        ]);
    }

    /**
     * @param array<string, string> $env
     */
    private function spawn(int $port, string $script, array $env): void
    {
        $descriptors = [['pipe', 'r'], ['file', '/dev/null', 'a'], ['file', '/dev/null', 'a']];
        $server = proc_open(
            sprintf('exec php -S 127.0.0.1:%d %s', $port, escapeshellarg($script)),
            $descriptors,
            $pipes,
            dirname(__DIR__),
            $env + getenv(),
        );

        if (!is_resource($server)) {
            $this->markTestSkipped('Could not start a loopback PHP server.');
        }

        $this->servers[] = $server;
        $this->waitForPort($port);
    }

    private function freePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($sock, 'Could not allocate a loopback port: ' . $errstr);
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        self::assertIsString($name);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private function waitForPort(int $port): void
    {
        for ($i = 0; $i < 100; $i++) {
            $conn = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 0.1);
            if (is_resource($conn)) {
                fclose($conn);

                return;
            }
            usleep(50_000);
        }

        self::fail('Loopback server on port ' . $port . ' never came up.');
    }
}
