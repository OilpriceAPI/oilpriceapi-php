<?php

declare(strict_types=1);

namespace OilPriceAPI\Tests;

use OilPriceAPI\Client;
use OilPriceAPI\Exception\ApiException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A raw API path must never change the origin the credential is sent to.
 *
 * The SDK concatenates the configured base URL with the caller-supplied path,
 * so a path that does not begin with a single '/' can graft an authority onto
 * the base host ('@evil.tld/v1/prices' -> 'https://api.oilpriceapi.com@evil.tld/...').
 * cURL then resolves 'evil.tld' as the host and still sends the customer's
 * 'Authorization: Token <key>' header.
 */
final class RawPathOriginTest extends TestCase
{
    private const BASE = 'https://api.oilpriceapi.com';
    private const KEY = 'fixture_key_NOT_REAL_0123456789';

    /**
     * Paths that would move the request off the configured origin.
     *
     * @return array<string, array{string}>
     */
    public static function offOriginPaths(): array
    {
        return [
            'scheme-relative' => ['//fixture.invalid/v1/prices'],
            'scheme-relative with userinfo' => ['//user@fixture.invalid/v1/prices'],
            'userinfo append' => ['@fixture.invalid/v1/prices'],
            'userinfo append with password' => [':pw@fixture.invalid/v1/prices'],
            'userinfo append with query' => ['@fixture.invalid/v1/prices?by_code=BRENT_CRUDE_USD'],
            'userinfo append with fragment' => ['@fixture.invalid/v1/prices#frag'],
            'backslash authority' => ['\\\\fixture.invalid/v1/prices'],
            'mixed slash backslash' => ['/\\fixture.invalid/v1/prices'],
            'absolute foreign url' => ['http://fixture.invalid/v1/prices'],
            'uppercase scheme' => ['HTTP://fixture.invalid/v1/prices'],
            'scheme downgrade on our own host' => ['http://api.oilpriceapi.com/v1/prices'],
            'leading whitespace userinfo' => [' @fixture.invalid/v1/prices'],
            'tab padded userinfo' => ["\t@fixture.invalid/v1/prices"],
            'empty path' => [''],
        ];
    }

    #[DataProvider('offOriginPaths')]
    public function testOffOriginRawPathIsRejectedBeforeAnyRequestIsMade(string $path): void
    {
        $transport = new MockTransport();
        $transport->queue(200, ['status' => 'success', 'data' => ['code' => 'X', 'price' => 1.0]]);
        $client = new Client(self::KEY, self::BASE, 10.0, 0, $transport);

        try {
            $client->raw()->get($path);
            $this->fail(sprintf('Expected off-origin path %s to be rejected.', var_export($path, true)));
        } catch (ApiException) {
            // expected
        }

        $this->assertSame(
            0,
            $transport->requestCount(),
            sprintf('No outbound request may be made for off-origin path %s.', var_export($path, true)),
        );
    }

    /**
     * Paths that look hostile but resolve to our own origin: they must stay
     * on the configured host rather than being rejected outright.
     *
     * @return array<string, array{string}>
     */
    public static function sameOriginPaths(): array
    {
        return [
            'percent-encoded slashes' => ['%2f%2ffixture.invalid/v1/prices'],
            'dot-dot traversal' => ['../../v1/prices/latest'],
            'host suffix append' => ['.fixture.invalid/v1/prices'],
            'no leading slash' => ['v1/prices/latest'],
            'leading slash' => ['/v1/prices/latest'],
            'with query' => ['/v1/prices/latest?extra=1'],
            'with fragment' => ['/v1/prices/latest#section'],
        ];
    }

    #[DataProvider('sameOriginPaths')]
    public function testSameOriginPathsKeepTheConfiguredHost(string $path): void
    {
        $transport = new MockTransport();
        $transport->queue(200, ['status' => 'success', 'data' => ['code' => 'X', 'price' => 1.0]]);
        $client = new Client(self::KEY, self::BASE, 10.0, 0, $transport);

        $client->raw()->get($path);

        $this->assertSame(1, $transport->requestCount());
        $url = $transport->requests[0]['url'];
        $this->assertSame(
            'api.oilpriceapi.com',
            parse_url($url, PHP_URL_HOST),
            sprintf('Path %s resolved off-origin to %s', var_export($path, true), $url),
        );
        $this->assertSame('https', parse_url($url, PHP_URL_SCHEME));
        $this->assertNull(parse_url($url, PHP_URL_USER), 'Resolved URL must carry no userinfo: ' . $url);
        $this->assertSame('Token ' . self::KEY, $transport->requests[0]['headers']['Authorization']);
    }

    public function testExplicitCustomBaseUrlStillWorks(): void
    {
        $transport = new MockTransport();
        $transport->queue(200, ['status' => 'success', 'data' => ['code' => 'X', 'price' => 1.0]]);
        $client = new Client(self::KEY, 'http://127.0.0.1:8080/proxy', 10.0, 0, $transport);

        $client->raw()->get('/v1/prices/latest');

        $this->assertSame(
            'http://127.0.0.1:8080/proxy/v1/prices/latest',
            $transport->requests[0]['url'],
        );
    }

    public function testModelledEndpointsStillResolve(): void
    {
        $transport = new MockTransport();
        $transport->queue(200, ['status' => 'success', 'data' => ['code' => 'BRENT_CRUDE_USD', 'price' => 71.8]]);
        $client = new Client(self::KEY, self::BASE, 10.0, 0, $transport);

        $client->latest('BRENT_CRUDE_USD');

        $this->assertSame(
            'https://api.oilpriceapi.com/v1/prices/latest?by_code=BRENT_CRUDE_USD',
            $transport->requests[0]['url'],
        );
    }

    /**
     * End-to-end proof over the real cURL transport: the API key must never
     * arrive at a foreign origin. A loopback server on a second port stands in
     * for the attacker host; the configured base URL points somewhere else.
     */
    public function testRealCurlTransportNeverDeliversTheKeyToAForeignOrigin(): void
    {
        $root = dirname(__DIR__);
        $log = tempnam(sys_get_temp_dir(), 'opa-wire-');
        self::assertIsString($log);
        file_put_contents($log, '');

        $port = self::freePort();
        $descriptors = [['pipe', 'r'], ['file', '/dev/null', 'a'], ['file', '/dev/null', 'a']];
        $server = proc_open(
            sprintf('exec php -S 127.0.0.1:%d %s', $port, escapeshellarg($root . '/tests/fixtures/capture.php')),
            $descriptors,
            $pipes,
            $root,
            ['CAPTURE_LOG' => $log] + getenv(),
        );

        if (!is_resource($server)) {
            $this->markTestSkipped('Could not start a loopback PHP server.');
        }

        try {
            self::waitForPort($port);

            // Base URL points at a different (closed) port; the raw path tries to
            // graft the capture server on as the real authority.
            $client = new Client(self::KEY, 'http://127.0.0.1:' . self::freePort(), 3.0, 0);

            try {
                $client->raw()->get(sprintf('@127.0.0.1:%d/v1/prices', $port));
            } catch (ApiException) {
                // Either rejection or a connection failure to the configured
                // origin is acceptable; delivery to the foreign host is not.
            }

            $captured = (string) file_get_contents($log);
            $this->assertSame(
                '',
                trim($captured),
                'The foreign origin received a request from the SDK: ' . $captured,
            );
        } finally {
            proc_terminate($server);
            proc_close($server);
            @unlink($log);
        }
    }

    private static function freePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($sock, 'Could not allocate a loopback port: ' . $errstr);
        $name = stream_socket_get_name($sock, false);
        fclose($sock);
        self::assertIsString($name);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private static function waitForPort(int $port): void
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
