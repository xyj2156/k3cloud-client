<?php

declare(strict_types=1);

namespace K3Cloud\Exception;

/**
 * Raised when authentication fails: bad credentials, a rejected login, or a
 * session that could not be established/re-established.
 */
final class AuthException extends K3CloudException
{
}
