<?php

declare(strict_types=1);

namespace K3Cloud\Auth;

use K3Cloud\Config;
use K3Cloud\Http\HttpRequest;
use K3Cloud\Http\HttpResponse;
use K3Cloud\Http\Transport;

/**
 * Adds the authentication material (headers and/or cookies) a request needs and
 * reacts to responses that indicate the credentials/session must be refreshed.
 */
interface AuthStrategy
{
    /**
     * Return a strategy re-based onto a new Config and Transport, carrying over
     * any reusable session state when the credential identity is unchanged.
     *
     * Used when a client fluent option (TLS / timeouts) rebuilds the transport:
     * a stateless strategy just rebuilds; a session strategy keeps its live
     * session so a mid-stream reconfigure does not force a re-login.
     */
    public function withDependencies(Config $config, Transport $transport): self;

    /**
     * Return a copy of $request decorated with auth headers / cookies.
     * Implementations may lazily perform a login round-trip here.
     */
    public function decorate(HttpRequest $request): HttpRequest;

    /**
     * True when a previous response indicates the session is no longer valid
     * and a single transparent retry after re-authentication is worthwhile.
     */
    public function shouldRetry(HttpRequest $request, HttpResponse $response): bool;
}
