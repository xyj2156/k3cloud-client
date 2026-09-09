<?php

declare(strict_types=1);

namespace K3Cloud\Tests;

use K3Cloud\Auth\SessionAuth;
use K3Cloud\Config;
use K3Cloud\Exception\AuthException;
use K3Cloud\Http\HttpRequest;
use PHPUnit\Framework\TestCase;

final class SessionAuthTest extends TestCase
{
    private function config(): Config
    {
        return Config::password(
            serverUrl: 'https://cloud.example.com/K3Cloud',
            acctId: 'acct123',
            userName: 'tester',
            password: 'p@ssw0rd',
        );
    }

    public function testLoginIsLazyAndAttachesSessionCookie(): void
    {
        $transport = new FakeTransport();
        // First send() is the ValidateUser login; queue its response.
        $transport->queueResponse(200, '{"LoginResultType":1,"KDSVCSessionId":"abc"}', [
            'Set-Cookie' => ['kdsessionid=abc; path=/; HttpOnly'],
        ]);

        $auth = new SessionAuth($this->config(), $transport);
        self::assertCount(0, $transport->requests, 'must not log in eagerly');

        $apiReq = new HttpRequest('POST', 'https://cloud.example.com/K3Cloud/x.common.kdsvc', '{}');
        $decorated = $auth->decorate($apiReq);

        self::assertCount(1, $transport->requests, 'login happened on first decorate');
        self::assertStringContainsString('AuthService.ValidateUser', $transport->lastRequest()->url);
        self::assertSame(['kdsessionid' => 'abc'], $decorated->cookies());
    }

    public function testLoginFailureThrowsAuthException(): void
    {
        $transport = new FakeTransport();
        $transport->queueResponse(200, '{"LoginResultType":-1,"Message":"用户名或密码错误"}');

        $auth = new SessionAuth($this->config(), $transport);

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('用户名或密码错误');
        $auth->decorate(new HttpRequest('POST', 'https://cloud.example.com/K3Cloud/x.common.kdsvc', '{}'));
    }

    public function testExpiryMarkerTriggersOneRetryFlagAndInvalidation(): void
    {
        $transport = new FakeTransport();
        $transport->queueResponse(200, '{"LoginResultType":1,"KDSVCSessionId":"abc"}', [
            'Set-Cookie' => ['kdsessionid=abc; path=/'],
        ]);
        $auth = new SessionAuth($this->config(), $transport);
        $auth->decorate(new HttpRequest('POST', 'https://cloud.example.com/K3Cloud/x.common.kdsvc', '{}'));

        $expired = new \K3Cloud\Http\HttpResponse(200, '{"Result":"会话已失效"}');
        self::assertTrue($auth->shouldRetry(new HttpRequest('POST', 'u', '{}'), $expired));
        self::assertFalse($auth->isLoggedIn(), 'session dropped so next call re-authenticates');
    }
}
