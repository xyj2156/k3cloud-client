<?php

declare(strict_types=1);

namespace K3Cloud\Http;

/**
 * Performs a single HTTP request and returns the raw response.
 *
 * Implementations must not throw for non-2xx statuses — surface them via
 * {@see HttpResponse::status()} so callers can decide. Only genuine transport
 * failures (DNS, TLS, timeout, cURL error) should raise a TransportException.
 */
interface Transport
{
    public function send(HttpRequest $request): HttpResponse;
}
