# Design: a fluent Bill builder (二开-safe)

Status: **proposed** (design only — not yet implemented). This document locks the
intended API before any code is written.

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

## Open decisions for implementation time

1. Class name: `Bill` vs `Model` vs `Document` (avoid clash with `K3Cloud\Result` style).
2. Whether `save()/draft()/submit()` overloads accept a `Bill` (likely yes) —
   the current methods already take `array|string`, so a `Bill` just needs to be
   `Stringable`/`toArray()`-resolved or a small union widened.
3. `lines()` reuse: does the closure receive a fresh sub-builder or an array.
