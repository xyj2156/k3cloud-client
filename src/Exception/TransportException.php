<?php

declare(strict_types=1);

namespace K3Cloud\Exception;

/**
 * Thrown when the underlying transport fails: DNS errors, TLS problems,
 * connection timeouts, or a cURL-level failure. Carries no HTTP semantics.
 */
final class TransportException extends K3CloudException
{
}
