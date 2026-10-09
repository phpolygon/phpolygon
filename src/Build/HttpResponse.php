<?php

declare(strict_types=1);

namespace PHPolygon\Build;

/** Status, body and headers of one {@see HttpClient} request; status 0 = no response. */
final class HttpResponse
{
    /**
     * @param array<string, string> $headers name => value
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
        /** Why there was no response (status 0), for the build log. */
        public readonly string $error = '',
    ) {}

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function isRedirect(): bool
    {
        return in_array($this->status, [301, 302, 303, 307, 308], true) && $this->header('Location') !== null;
    }

    /** A header by case-insensitive name. */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return null;
    }
}
