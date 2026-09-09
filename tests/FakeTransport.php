<?php

declare(strict_types=1);

namespace K3Cloud\Tests;

use K3Cloud\Http\HttpRequest;
use K3Cloud\Http\HttpResponse;
use K3Cloud\Http\Transport;

/**
 * In-memory transport used by unit tests. It queues canned responses and
 * records every request it was asked to send.
 */
final class FakeTransport implements Transport
{
    /** @var list<HttpResponse> */
    private array $queue = [];

    /** @var list<HttpRequest> */
    public array $requests = [];

    public function queueResponse(int $status, string $body, array $headers = []): void
    {
        $normalised = [];
        foreach ($headers as $name => $values) {
            $normalised[strtolower((string) $name)] = is_array($values) ? $values : [$values];
        }
        $this->queue[] = new HttpResponse($status, $body, $normalised);
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;

        if ($this->queue === []) {
            return new HttpResponse(200, '{}', []);
        }

        return array_shift($this->queue);
    }

    public function lastRequest(): ?HttpRequest
    {
        return $this->requests[array_key_last($this->requests)] ?? null;
    }
}
