<?php

declare(strict_types=1);

namespace K3Cloud\Exception;

/**
 * Thrown when the supplied configuration is incomplete or contradictory
 * (missing server URL, unknown auth mode, malformed application id, ...).
 */
final class ConfigException extends K3CloudException
{
}
