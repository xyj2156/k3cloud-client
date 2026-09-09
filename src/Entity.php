<?php

declare(strict_types=1);

namespace K3Cloud;

use K3Cloud\Exception\ConfigException;

/**
 * Schema-free fluent builder for a K/3 Cloud form payload (the Save/Draft family).
 *
 * This is the base class. It works standalone via {@see K3CloudClient::bill()} for
 * ANY entity with NO schema, and is designed to be subclassed per entity as optional
 * sugar. Because it never gates keys, 二开 / extension fields always flow through
 * {@see self::custom()} / {@see self::package()} unchanged.
 *
 * The builder models the whole "data" object sent after the form id — top-level
 * control flags (NeedUpDateFields, IsVerifyBaseDataField, …) plus a nested
 * `Model`. Field setters write into `Model`; control flags have their own setters.
 *
 * Every fluent mutator returns `static` so the concrete subclass type survives the
 * chain and reaches a terminal (save/draft/call), which return {@see Result}.
 *
 *   final class SaleOrder extends Entity {
 *       public const FORM_ID = 'SAL_SaleOrder';
 *       public function customer(string $n): static { return $this->ref('FCustId', $n); }
 *   }
 *   SaleOrder::for($api)->customer('16.195.06.0001')->custom('F_PEYC_Decimal','1710')->save();
 */
class Entity
{
    /** Concrete subclasses override this with their form id. */
    public const FORM_ID = '';

    /** @var array<string,mixed> the assembled data payload */
    protected array $data = [];

    /** @var array<string,class-string<self>> form id => developer class */
    private static array $registry = [];

    public function __construct(
        protected readonly K3CloudClient $api,
        protected readonly string $formId,
        array $initial = [],
    ) {
        if ($initial !== []) {
            $this->data = $this->seed($initial);
        }
    }

    /* ---------------------------------------------------------------------
     |  Factories / entry points
     * ------------------------------------------------------------------- */

    /**
     * Typed, class-static factory. `$order = SaleOrder::for($api)` resolves to
     * SaleOrder in every IDE (the `: static` return). Subclasses just define FORM_ID.
     *
     * @param array<string,mixed> $initial full data payload, or a Model dict (see seed())
     */
    public static function for(K3CloudClient $api, array $initial = []): static
    {
        $formId = static::FORM_ID;
        if ($formId === '') {
            throw new ConfigException('Entity::for() needs a FORM_ID on the subclass; use $api->bill($formId) for the generic builder.');
        }

        return new static($api, $formId, $initial);
    }

    /**
     * Register a developer entity class so the generic {@see K3CloudClient::bill()}
     * auto-resolves `$formId` to it. Purely a convenience: an unregistered form id
     * falls back to the base Entity, never an error.
     *
     * @param class-string<self> $class
     */
    public static function register(string $formId, string $class): void
    {
        if ($class !== self::class && !is_subclass_of($class, self::class)) {
            throw new ConfigException("Registered class $class must extend " . self::class);
        }
        self::$registry[$formId] = $class;
    }

    /** @return class-string<self>|null */
    public static function resolve(string $formId): ?string
    {
        return self::$registry[$formId] ?? null;
    }

    public function formId(): string
    {
        return $this->formId;
    }

    /* ---------------------------------------------------------------------
     |  Model field mutators (schema-free; all :static)
     * ------------------------------------------------------------------- */

    /**
     * Set a scalar/plain field on the Model.
     */
    public function set(string $key, mixed $value): static
    {
        $model = &$this->modelRef();
        $model[$key] = $value;
        unset($model);

        return $this;
    }

    /**
     * Set a base-data reference field: {"FNumber": $number} (per-call casing override).
     */
    public function ref(string $key, string $number, string $refKey = 'FNumber'): static
    {
        $model = &$this->modelRef();
        $model[$key] = [$refKey => $number];
        unset($model);

        return $this;
    }

    /**
     * Attach a custom / 二开 field. Same as set(), named for intent and kept
     * first-class so extension fields never require SDK support.
     */
    public function custom(string $key, mixed $value): static
    {
        return $this->set($key, $value);
    }

    /**
     * Append ONE row to a detail/entry segment — the ONLY append path.
     *
     * @param array<string,mixed>|callable(Line):void $row
     */
    public function line(string $segment, array|callable $row = []): static
    {
        $built = is_callable($row)
            ? (static function (Line $l) use ($row): array { $row($l); return $l->toArray(); })(new Line())
            : $row;

        $model = &$this->modelRef();
        $model[$segment][] = $built;
        unset($model);

        return $this;
    }

    /**
     * Recursively merge a structure into the Model (assoc deep-merged, list values
     * replaced). Use for partial sub-objects like {"FSaleOrderFinance": {...}}.
     *
     * @param array<string,mixed> $structure
     */
    public function package(array $structure): static
    {
        $model = &$this->modelRef();
        $model = self::mergeAssoc($model, $structure);
        unset($model);

        return $this;
    }

    /**
     * Recursively merge into the WHOLE payload (control flags + Model). Advanced
     * escape hatch for anything the named setters do not cover.
     *
     * @param array<string,mixed> $structure
     */
    public function mergePayload(array $structure): static
    {
        $this->data = self::mergeAssoc($this->data, $structure);

        return $this;
    }

    /**
     * Set a top-level control flag on the payload (NeedUpDateFields, SubSystemId,
     * IsVerifyBaseDataField, ValidateRepeatJson, …).
     */
    public function control(string $key, mixed $value): static
    {
        $this->data[$key] = $value;

        return $this;
    }

    /* ---------------------------------------------------------------------
     |  Output & terminals
     * ------------------------------------------------------------------- */

    /** @return array<string,mixed> the data payload, ready to post after the form id */
    public function toArray(): array
    {
        $payload = $this->data;
        $payload['Model'] ??= [];

        return $payload;
    }

    public function save(?array $patch = null): Result
    {
        return $this->api->save($this->formId, $this->build($patch));
    }

    public function draft(?array $patch = null): Result
    {
        return $this->api->draft($this->formId, $this->build($patch));
    }

    /**
     * Generic escape hatch: post the current payload to any DynamicFormService op.
     */
    public function call(string $op, ?array $patch = null): Result
    {
        return $this->api->operation($op, [$this->formId, $this->build($patch)]);
    }

    /**
     * @param array<string,mixed>|null $patch merged into the Model before sending
     *
     * @return array<string,mixed>
     */
    private function build(?array $patch): array
    {
        $payload = $this->toArray();
        if ($patch !== null && $patch !== []) {
            $payload['Model'] = self::mergeAssoc($payload['Model'], $patch);
        }

        return $payload;
    }

    /* ---------------------------------------------------------------------
     |  Internals
     * ------------------------------------------------------------------- */

    /** Control keys that mark an $initial array as a full payload (vs a Model dict). */
    private const CONTROL_KEYS = [
        'Model', 'Creator', 'NeedUpDateFields', 'NeedSelectFields', 'NeedReturnFields',
        'IsDeleteEntry', 'SubSystemId', 'IsAutoSubmitAndAudit', 'IsVerifyBaseDataField',
        'InterationFlags', 'IgnoreInterationFlag', 'NetworkControl', 'ValidateRepeatJson',
        'NumberSearchField', 'TargetForm', 'UseBatControlTransform', 'MRPMsgMode',
    ];

    /**
     * Accept either a full data payload (has a control key) or a bare Model dict.
     *
     * @param array<string,mixed> $initial
     *
     * @return array<string,mixed>
     */
    private function seed(array $initial): array
    {
        $isPayload = array_key_exists('Model', $initial)
            || array_intersect_key($initial, array_flip(self::CONTROL_KEYS)) !== [];

        return $isPayload ? $initial : ['Model' => $initial];
    }

    /**
     * @return array<string,mixed> reference to the Model sub-array (created if absent)
     */
    private function &modelRef(): array
    {
        if (!isset($this->data['Model']) || !is_array($this->data['Model'])) {
            $this->data['Model'] = [];
        }

        return $this->data['Model'];
    }

    /**
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
