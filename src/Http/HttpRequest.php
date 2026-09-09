<?php

declare(strict_types=1);

namespace K3Cloud\Http;

/**
 * An outgoing HTTP request, transport agnostic.
 *
 * Headers are stored case-preserving but looked up case-insensitively.
 */
final class HttpRequest
{
    /** @var array<string,string> */
    private array $headers = [];

    /** @var array<string,string> */
    private array $cookies = [];

    /**
     * @param array<string,string|int> $headers
     * @param array<string,string>     $cookies
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly string $body = '',
        array $headers = [],
        array $cookies = [],
    ) {
        foreach ($headers as $name => $value) {
            $this->headers[(string) $name] = (string) $value;
        }
        foreach ($cookies as $name => $value) {
            $this->cookies[$name] = $value;
        }
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    public function withCookie(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->cookies[$name] = $value;

        return $clone;
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** @return array<string,string> */
    public function cookies(): array
    {
        return $this->cookies;
    }
}
