<?php

declare(strict_types=1);

namespace K3Cloud;

/**
 * A single detail/entry row builder used inside {@see Entity::line()}.
 *
 * Same field API as the parent entity (set / ref / custom / package) so 二开
 * fields work on lines too, but it only produces a flat row array — it does not
 * talk to the server. All mutators are `: static` so the concrete subclass type
 * survives a fluent chain.
 */
class Line
{
    /** @var array<string,mixed> */
    protected array $row = [];

    public function __construct(array $initial = [])
    {
        $this->row = $initial;
    }

    /**
     * Set a scalar field on the row.
     */
    public function set(string $key, mixed $value): static
    {
        $this->row[$key] = $value;

        return $this;
    }

    /**
     * Set a base-data reference field: {"FNumber": $number} by default.
     */
    public function ref(string $key, string $number, string $refKey = 'FNumber'): static
    {
        $this->row[$key] = [$refKey => $number];

        return $this;
    }

    /**
     * Attach a custom / 二开 field. Identical to set(), separated for intent and
     * forward-compatibility (this is the schema-free escape hatch on a row).
     */
    public function custom(string $key, mixed $value): static
    {
        return $this->set($key, $value);
    }

    /**
     * Recursively merge a structure into this row (list values replace).
     *
     * @param array<string,mixed> $structure
     */
    public function package(array $structure): static
    {
        $this->row = self::mergeAssoc($this->row, $structure);

        return $this;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->row;
    }

    /**
     * Assoc arrays deep-merged; list/entry arrays replaced; scalars overwritten.
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $over
     *
     * @return array<string,mixed>
     */
    protected static function mergeAssoc(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            if (is_array($value) && array_is_list($value)) {
                $base[$key] = $value;
                continue;
            }
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !array_is_list($base[$key])) {
                $base[$key] = self::mergeAssoc($base[$key], $value);
                continue;
            }
            $base[$key] = $value;
        }

        return $base;
    }
}
