<?php

declare(strict_types=1);

namespace K3Cloud\Exception;

/**
 * 认证失败时抛出：凭据错误、登录被拒，或会话无法建立/重建。
 */
final class AuthException extends K3CloudException
{
}
