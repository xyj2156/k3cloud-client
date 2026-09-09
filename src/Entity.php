<?php

declare(strict_types=1);

namespace K3Cloud;

use K3Cloud\Exception\ConfigException;

/**
 * 面向 K/3 Cloud 表单载荷（Save/Draft 族）的 schema-free 流式构建器。
 *
 * 这是基类。它可通过 {@see K3CloudClient::bill()} 独立用于**任意**实体、无需 schema，
 * 并被设计为可按实体子类化作为可选糖。由于它从不限制键名，二开 / 扩展字段总能原样通过
 * {@see self::custom()} / {@see self::package()} 传入。
 *
 * 构建器建模的是跟在 form id 之后的整个 "data" 对象——顶层控制位（NeedUpDateFields、
 * IsVerifyBaseDataField……）加上嵌套的 `Model`。字段 setter 写入 `Model`；控制位各有 setter。
 *
 * 每个流式 mutator 都返回 `static`，使具体子类类型贯穿链式调用并到达终端（save/draft/call），
 * 终端返回 {@see Result}。
 *
 *   final class SaleOrder extends Entity {
 *       public const FORM_ID = 'SAL_SaleOrder';
 *       public function customer(string $n): static { return $this->ref('FCustId', $n); }
 *   }
 *   SaleOrder::for($api)->customer('16.195.06.0001')->custom('F_PEYC_Decimal','1710')->save();
 */
class Entity
{
    /** 具体子类用它覆盖为自己的 form id。 */
    public const FORM_ID = '';

    /** @var array<string,mixed> 已组装的数据载荷 */
    protected array $data = [];

    /** @var array<string,class-string<self>> form id => 开发者类 */
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
     |  工厂 / 入口
     * ------------------------------------------------------------------- */

    /**
     * 带类型的类静态工厂。`$order = SaleOrder::for($api)` 在每个 IDE 里都解析为 SaleOrder
     * （`: static` 返回）。子类只需定义 FORM_ID。
     *
     * @param array<string,mixed> $initial 完整数据载荷，或 Model 字典（见 seed()）
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
     * 注册一个开发者实体类，使通用 {@see K3CloudClient::bill()} 能把 `$formId` 自动解析到它。
     * 纯属便利：未注册的 form id 回退到基类 Entity，从不报错。
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
     |  Model 字段 mutator（schema-free；全部 :static）
     * ------------------------------------------------------------------- */

    /**
     * 在 Model 上设置一个标量/普通字段。
     */
    public function set(string $key, mixed $value): static
    {
        $model = &$this->modelRef();
        $model[$key] = $value;
        unset($model);

        return $this;
    }

    /**
     * 设置一个基础资料引用字段：{"FNumber": $number}（可按次覆盖引用键的大小写）。
     */
    public function ref(string $key, string $number, string $refKey = 'FNumber'): static
    {
        $model = &$this->modelRef();
        $model[$key] = [$refKey => $number];
        unset($model);

        return $this;
    }

    /**
     * 附加一个自定义 / 二开字段。与 set() 相同，仅为语义命名并保持其一等地位，
     * 使扩展字段永不要求 SDK 支持。
     */
    public function custom(string $key, mixed $value): static
    {
        return $this->set($key, $value);
    }

    /**
     * 向某个分录/明细段追加**一行**——唯一的追加入口。
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
     * 把一个结构递归合并进 Model（关联数组深合并、列表值整体替换）。用于像
     * {"FSaleOrderFinance": {...}} 这样的局部子对象。
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
     * 递归合并进**整个**载荷（控制位 + Model）。为具名 setter 未覆盖到的任何内容
     * 预留的高级逃生口。
     *
     * @param array<string,mixed> $structure
     */
    public function mergePayload(array $structure): static
    {
        $this->data = self::mergeAssoc($this->data, $structure);

        return $this;
    }

    /**
     * 在载荷上设置一个顶层控制位（NeedUpDateFields、SubSystemId、
     * IsVerifyBaseDataField、ValidateRepeatJson……）。
     */
    public function control(string $key, mixed $value): static
    {
        $this->data[$key] = $value;

        return $this;
    }

    /* ---------------------------------------------------------------------
     |  输出与终端
     * ------------------------------------------------------------------- */

    /** @return array<string,mixed> 数据载荷，可直接跟在 form id 之后发送 */
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
     * 通用逃生口：把当前载荷发送到任意 DynamicFormService 操作。
     */
    public function call(string $op, ?array $patch = null): Result
    {
        return $this->api->operation($op, [$this->formId, $this->build($patch)]);
    }

    /**
     * @param array<string,mixed>|null $patch 发送前合并进 Model
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
     |  内部实现
     * ------------------------------------------------------------------- */

    /** 用于把 $initial 数组判定为"完整载荷"（而非 Model 字典）的控制键。 */
    private const CONTROL_KEYS = [
        'Model', 'Creator', 'NeedUpDateFields', 'NeedSelectFields', 'NeedReturnFields',
        'IsDeleteEntry', 'SubSystemId', 'IsAutoSubmitAndAudit', 'IsVerifyBaseDataField',
        'InterationFlags', 'IgnoreInterationFlag', 'NetworkControl', 'ValidateRepeatJson',
        'NumberSearchField', 'TargetForm', 'UseBatControlTransform', 'MRPMsgMode',
    ];

    /**
     * 接受"完整数据载荷（含控制键）"或"裸 Model 字典"两种形式。
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
     * @return array<string,mixed> 指向 Model 子数组的引用（不存在则创建）
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
