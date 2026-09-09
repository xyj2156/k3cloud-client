<?php

declare(strict_types=1);

namespace K3Cloud\Exception;

/**
 * 传入的配置不完整或自相矛盾时抛出（缺少服务地址、未知认证方式、应用 ID 格式错误……）。
 */
final class ConfigException extends K3CloudException
{
}
