<?php

declare(strict_types=1);

namespace PHPolygon\Build;

/** {@see HttpClient} over PHP's http stream wrapper (no curl needed). */
final class StreamHttpClient implements HttpClient
{
    public function __construct(private readonly int $timeout = 120) {}

    public function get(string $url, array $headers): HttpResponse
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => $headers,
                'timeout' => $this->timeout,
                'follow_location' => 0,
                'ignore_errors' => true,
            ],
        ]);

        error_clear_last();
        $body = @file_get_contents($url, false, $context);
        $lines = http_get_last_response_headers() ?? [];
        return self::response($lines, $body === false ? '' : $body, error_get_last()['message'] ?? '');
    }

    /**
     * Parse the raw response header lines of the stream wrapper.
     *
     * @param array<int, string> $lines
     */
    public static function response(array $lines, string $body, string $error = ''): HttpResponse
    {
        $status = 0;
        $headers = [];
        foreach ($lines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
                $headers = [];
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon !== false) {
                $headers[trim(substr($line, 0, $colon))] = trim(substr($line, $colon + 1));
            }
        }
        return new HttpResponse($status, $body, $headers, $status === 0 ? ($error !== '' ? $error : 'no response') : '');
    }
}
