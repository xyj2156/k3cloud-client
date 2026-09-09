<?php

declare(strict_types=1);

namespace K3Cloud;

use K3Cloud\Exception\ApiException;

/**
 * A decoded, convenient view over a K/3 Cloud WebAPI response.
 *
 * The server returns several shapes depending on the operation:
 *  - Write/operate calls: {"Result": {"ResponseStatus": {"IsSuccess": bool, ...},
 *    "Id": .., "Number": "..", "Message": ..}}
 *  - Bill queries: a JSON array of rows (list of lists).
 *  - Plain data: a JSON array/object.
 *
 * Result exposes the common parts without forcing callers to hand-dig the tree.
 */
final class Result
{
    /** @var array<mixed>|string|int|float|bool|null */
    private mixed $payload;

    /**
     * @param array<mixed>|string|int|float|bool|null $payload
     */
    public function __construct(
        public readonly string $raw,
        mixed $payload,
        public readonly int $status = 200,
    ) {
        $this->payload = $payload;
    }

    /** The decoded body (array / scalar) or null when not JSON. */
    public function payload(): mixed
    {
        return $this->payload;
    }

    /** Alias for the decoded body. */
    public function array(): mixed
    {
        return $this->payload;
    }

    public function raw(): string
    {
        return $this->raw;
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    /** True for list-shaped responses (bill queries). */
    public function isList(): bool
    {
        return is_array($this->payload) && array_is_list($this->payload);
    }

    /**
     * Rows for a query response, or [] for anything else.
     *
     * @return list<mixed>
     */
    public function rows(): array
    {
        return $this->isList() ? $this->payload : [];
    }

    /**
     * @return array<mixed>|null
     */
    public function responseStatus(): ?array
    {
        if (!is_array($this->payload)) {
            return null;
        }
        $result = $this->payload['Result'] ?? $this->payload;
        if (is_array($result) && isset($result['ResponseStatus']) && is_array($result['ResponseStatus'])) {
            return $result['ResponseStatus'];
        }

        return null;
    }

    /**
     * Business success: ResponseStatus.IsSuccess when present; otherwise true if
     * the HTTP call itself succeeded and no error envelope was returned.
     */
    public function isSuccess(): bool
    {
        $status = $this->responseStatus();
        if ($status !== null) {
            // IsSuccess may arrive as bool or 0/1.
            return (bool) ($status['IsSuccess'] ?? false);
        }

        if (is_array($this->payload) && array_key_exists('IsSuccess', $this->payload)) {
            return (bool) $this->payload['IsSuccess'];
        }

        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Best-effort single error message, or null when successful.
     */
    public function errorMessage(): ?string
    {
        $status = $this->responseStatus();
        if ($status !== null) {
            $errors = $status['Errors'] ?? [];
            if (is_array($errors) && $errors !== []) {
                $first = reset($errors);
                if (is_array($first)) {
                    return (string) ($first['Message'] ?? $first['Description'] ?? json_encode($first, JSON_UNESCAPED_UNICODE));
                }

                return (string) $first;
            }
            if (!empty($status['MsgCode']) && (int) $status['MsgCode'] !== 0) {
                return 'Operation failed (MsgCode ' . $status['MsgCode'] . ').';
            }
        }

        if (is_array($this->payload)) {
            foreach (['Message', 'Result', 'description', 'Message'] as $key) {
                if (isset($this->payload[$key]) && is_string($this->payload[$key]) && $this->payload[$key] !== '') {
                    return $this->payload[$key];
                }
            }
        }

        return $this->isSuccess() ? null : 'Unknown API error.';
    }

    /**
     * New record internal id (Save/Draft), when present.
     */
    public function id(): int|string|null
    {
        if (!is_array($this->payload)) {
            return null;
        }
        $result = $this->payload['Result'] ?? null;

        return is_array($result) ? ($result['Id'] ?? $result['Number'] ?? null) : null;
    }

    /**
     * New record number (Save/Draft), when present.
     */
    public function number(): ?string
    {
        if (!is_array($this->payload)) {
            return null;
        }
        $result = $this->payload['Result'] ?? null;

        return is_array($result) && isset($result['Number']) ? (string) $result['Number'] : null;
    }

    /**
     * Throw an {@see ApiException} unless the operation reported success.
     */
    public function throwIfError(): self
    {
        if (!$this->isSuccess()) {
            throw new ApiException($this, $this->errorMessage() ?? 'API error.');
        }

        return $this;
    }
}
