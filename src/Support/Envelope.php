<?php

declare(strict_types=1);

namespace K3Cloud\Support;

use K3Cloud\Exception\K3CloudException;

/**
 * Builds the JSON body expected by every K/3 Cloud ".common.kdsvc" endpoint.
 *
 * The WebAPI uses a single positional envelope:
 *   {"parameters": [ p0, p1, ... ]}
 *
 * Associative/array parameters are embedded as nested JSON objects (they are NOT
 * double-encoded to strings), which is what the K/3 Cloud server accepts.
 */
final class Envelope
{
    /**
     * @param list<mixed> $parameters
     */
    public static function encode(array $parameters): string
    {
        $json = json_encode(
            ['parameters' => array_values($parameters)],
            JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            throw new K3CloudException('Failed to encode request envelope: ' . json_last_error_msg());
        }

        return $json;
    }
}
