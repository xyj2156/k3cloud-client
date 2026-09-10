<?php

declare(strict_types=1);

namespace K3Cloud;

/**
 * 在 {@see Entity::line()} 内使用的单行分录/明细行构造器。
 *
 * 与父实体相同的字段 API（set / ref / custom / package），使二开字段在行上也能用；
 * 但它只产出一个扁平的行数组——不与服务器通信。所有 mutator 都是 `: static`，
 * 使具体子类类型贯穿链式调用。
 */
class Line
{
    /** @var array<string,mixed> */
    protected array $row = [];

    /**
     * @param array<string,mixed> $initial 分录行的初始字段
     */
    public function __construct(array $initial = [])
    {
        $this->row = $initial;
    }

    /**
     * 在行上设置一个标量字段。
     */
    public function set(string $key, mixed $value): static
    {
        $this->row[$key] = $value;

        return $this;
    }

    /**
     * 设置一个基础资料引用字段：默认 {"FNumber": $number}。
     */
    public function ref(string $key, string $number, string $refKey = 'FNumber'): static
    {
        $this->row[$key] = [$refKey => $number];

        return $this;
    }

    /**
     * 附加一个自定义 / 二开字段。与 set() 完全相同，仅为语义与向后兼容而分开
     * （这是行上的 schema-free 逃生口）。
     */
    public function custom(string $key, mixed $value): static
    {
        return $this->set($key, $value);
    }

    /**
     * 把一个结构递归合并进本行（列表值整体替换）。
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
     * 关联数组深合并；列表/分录数组整体替换；标量覆盖。
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
