<?php

declare(strict_types=1);

namespace PHPolygon\Build;

/**
 * The GET requests a build makes to fetch runtime libraries. Redirects are NOT
 * followed: the caller decides which headers (a token) the next host gets.
 */
interface HttpClient
{
    /**
     * @param list<string> $headers "Name: value" lines
     */
    public function get(string $url, array $headers): HttpResponse;
}
