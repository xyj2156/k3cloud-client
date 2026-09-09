# Design: a fluent Bill builder (二开-safe)

Status: **design finalized** (decisions 1–4 locked; implementation pending go).
See [Locked design decisions](#locked-design-decisions) at the bottom for the
agreed contract. No code has been written yet.

## Problem

Writing a bill payload today means hand-assembling the deeply nested `Model` the
K/3 Cloud `Save`/`Draft` endpoints expect, e.g. from the production log:

```json
{
  "Model": {
    "FBillTypeID": { "FNUMBER": "XSDD01_SYS" },
    "FCustId":     { "FNumber": "16.195.06.0001" },
    "FSaleOrderEntry": [
      { "FMaterialId": { "FNumber": "02.04.03.089" }, "FQty": "1.000", "F_PEYC_Decimal": "1710" }
    ],
    "FSaleOrderFinance": { "FSettleCurrId": { "FNumber": "PRE001" } }
  }
}
```

The boilerplate (base-data references are `{"FNumber": …}` objects, entry arrays,
exact key casing) is the biggest source of hand-written errors. A fluent builder
should remove it — **without ever becoming a blocker for customised installs**.

## Guiding requirement (non-negotiable)

Installs carry many **二开 / extension fields** (observed namespaces in this
tenant: `F_PAEZ_*`, `F_PEYC_*`, `F_PDQP_*`), and new ones appear whenever the
customer extends the schema. Therefore:

> The builder MUST let callers attach arbitrary data — a single unknown field, a
> whole custom segment, or a pre-built nested structure — with zero schema
> support from the SDK. Metadata is a convenience that only *warns*; it never
> gates what can be sent. Any field the server accepts must be reachable through
> the escape hatch, even one the SDK has never heard of.

This is the top design priority; the sugar exists only for common shapes.

## Proposed API

```php
use K3Cloud\Bill;

$bill = Bill::for('SAL_SaleOrder')
    // plain scalar fields
    ->set('FBusinessType', 'NORMAL')
    ->set('FNote', '溯农单号： 448854')
    // base-data references -> {"FNumber": "..."}  (casing configurable, see below)
    ->ref('FBillTypeID', 'XSDD01_SYS')
    ->ref('FCustId',     '16.195.06.0001')
    ->ref('FSalerId',    '4207')
    // a single 二开 field on the header — no schema needed
    ->custom('F_PAEZ_lixi', 'xxx')
    // entry / detail lines
    ->lines('FSaleOrderEntry', function ($row) {
            $row->ref('FMaterialId', '02.04.03.089')
                ->set('FQty', '1.000')
                ->set('FSysPrice', '2000')
                ->custom('F_PEYC_Decimal', '1710');   // 二开 field on the line
        })
        ->lines('FSaleOrderEntry', function ($row) {
            $row->ref('FMaterialId', '02.04.03.146')->set('FQty', '4.000');
        })
    // the whole escape hatch: merge ANY structure verbatim
    ->package([
        'FSaleOrderFinance' => ['FSettleCurrId' => ['FNumber' => 'PRE001'], 'FExchangeRate' => '1'],
        'F_SomeBrandNew_Ext' => [ /* future 二开 segment, unknown to the SDK */ ],
    ]);

// hand it straight to the client
$result = $api->save($bill);
echo $result->id(), ' / ', $result->number();
```

### Method roles

| Method | Purpose | 二开-safe? |
|---|---|---|
| `set($key, $value)` | scalar field, value passed as-is | any key accepted |
| `ref($key, $number)` | wrap as a base-data reference object | sugar only |
| `custom($key, $value)` | **explicitly** mark/attach a custom field | yes |
| `lines($segment, callable\|array)` | append one row to an entry array | rows use same helpers |
| `package(array)` / `raw(array)` | **merge an arbitrary nested structure verbatim** (the escape hatch) | fully generic |
| `toArray()` | produce the `Model` payload | passthrough |

Key principle: `set/custom/package` never validate the *existence* of a key;
they only shape values. Nothing in the fluent layer can prevent sending a valid
customised payload.

## Reference-object casing

The log shows both casings for the same concept — `FBillTypeID:{FNUMBER}` vs
`FCustId:{FNumber}` — because K/3 Cloud accepts the base-data number property
case-insensitively per business object. The builder will default to `FNumber`
but accept an override:

```php
->ref('FBillTypeID', 'XSDD01_SYS', key: 'FNUMBER')   // per-call override
```

so a mismatch is never a blocker.

## Optional metadata-aware layer (convenience, never a gate)

`tools/build-metadata.php` produces `.docs/kingdee_field.standard.json`
(entity → segment → fields). A future `Bill::for($entity, $metadata)` could:

- validate/warn when a *standard* key looks misspelled,
- autocomplete/segment grouping,
- flag which keys are known `F_*` 二开 namespaces.

But every such check is a **warning**, and `.docs/kingdee_field.custom.json`
is shipped so the SDK stays honest about which fields are extensions. Even with
metadata loaded, unknown keys pass through unchanged.

## Out of scope (for this first increment)

- Field-type coercion (the export has `length` but not data type).
- Cross-segment dependency validation.
- Any runtime call to the server for schema (kept purely local from the export).

## Locked design decisions

Agreed 2026-09-09. These override any earlier "open" wording above.

**1 — Base-class strategy (schema-free).** The base builder class is named
`Entity` and is fully generic: `set / ref / custom / line / package` all live on
it, so a bare `Entity` (or the `->bill()` factory) can build **any** form — all
50 now, and any future entity or 二开 field — with zero schema. Generated
per-entity subclasses are **optional sugar only**: they add segment accessors
(e.g. `entryLine()` → `FSaleOrderEntry`) and **field-name constants** for IDE
completion. They do NOT generate one-method-per-field (7 131 fields would explode)
and they never gate unknown keys. Unknown/extension fields always flow through
`custom()` / `package()` unchanged.

**2 — Entry point (real code, no alias table).** `->bill(string $formId, array
$initial = []): Entity` is the single source of truth. `__call` sugar
`$api->SAL_SaleOrder($data)` resolves the **real `entity_code`** (no invented
short alias, nothing to hand-maintain) via a generated registry of valid codes;
an unknown code throws `ConfigException`. Entity codes never collide with the
camelCase client methods.

**3 — Terminals & metadata role (completion only, NO validation).**
- Model family terminates with **named methods**: `->save($patch = null)`,
  `->draft(...)`, … returning the existing `K3Cloud\Result`.
- Identifier / operation family (`submit / audit / unaudit / delete / view`,
  payload shaped `{Numbers, Ids, CreateOrgId, InterationFlags…}`) stays a **raw
  array** via the existing client methods — not the Model builder (different
  payload family).
- A generic `->call(string $op, ?array $patch = null): Result` escape hatch stays
  on the base for rare Model-family endpoints.
- **No runtime validation and no runtime `withMetadata()`/injection.** Metadata is
  used only to **generate editor-time completion**: `.phpstorm.meta.php` (+ field
  constants) for Model field names AND for the non-Model op payload keys, so you
  don't have to memorize them. Missing keys are simply not completed and never
  error — completion is a convenience, not a contract. A user-supplied metadata
  file is a **build-time, additive (叠加)** input to the generator (merge their
  二开 fields over the default set → regenerate stubs); it is not loaded at runtime.

**4 — Merge semantics.**
- `line($segment, $row)` is the **only append path** (adds one row).
- `set / ref / custom($key,…)` = overwrite that key (later wins).
- `package(array)` and the terminal `$patch` = recursive merge: associative
  arrays deep-merged, scalars & reference objects overwritten, but **list /
  entry-array values are replaced, never appended** (avoids accidental duplicate
  lines on the non-idempotent Save).
- The builder models the **whole Save data payload** (top-level control flags such
  as `NeedUpDateFields`, `IsVerifyBaseDataField` get their own named setters);
  `Model` is a sub-key of it, and `$patch` merges at the payload level.
- The `line()` closure receives a **fresh row sub-builder** (same
  `set/ref/custom` API) so extension fields work on lines too.

Implementation is deferred until an explicit go.
