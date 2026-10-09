<?php

declare(strict_types=1);

namespace PHPolygon\Tests\Build;

use PHPolygon\Build\HttpResponse;
use PHPolygon\Build\StreamHttpClient;
use PHPUnit\Framework\TestCase;

final class StreamHttpClientTest extends TestCase
{
    public function testRedirectStatusAndLocation(): void
    {
        $response = StreamHttpClient::response(['HTTP/1.1 302 Found', 'location: https://objects.example/x', 'Content-Length: 0'], '');
        $this->assertSame(302, $response->status);
        $this->assertTrue($response->isRedirect());
        $this->assertSame('https://objects.example/x', $response->header('Location'));
    }

    public function testLastStatusLineWins(): void
    {
        $response = StreamHttpClient::response(['HTTP/1.1 100 Continue', 'X: 1', 'HTTP/1.1 200 OK', 'Content-Type: application/json'], '{}');
        $this->assertSame(200, $response->status);
        $this->assertTrue($response->ok());
        $this->assertNull($response->header('X'));
    }

    public function testNoResponseCarriesTheError(): void
    {
        $response = StreamHttpClient::response([], '', 'getaddrinfo failed');
        $this->assertSame(0, $response->status);
        $this->assertFalse($response->ok());
        $this->assertSame('getaddrinfo failed', $response->error);
    }

    public function testRedirectWithoutLocationIsNoRedirect(): void
    {
        $this->assertFalse((new HttpResponse(302, ''))->isRedirect());
    }
}
