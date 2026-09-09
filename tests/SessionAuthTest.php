<?php

declare(strict_types=1);

namespace K3Cloud\Tests;

use K3Cloud\Auth\SignatureAuth;
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

    public function testRebaseKeepsSessionWhenIdentityUnchanged(): void
    {
        $login = new FakeTransport();
        $login->queueResponse(200, '{"LoginResultType":1,"KDSVCSessionId":"abc"}', [
            'Set-Cookie' => ['kdsessionid=abc; path=/'],
        ]);
        $auth = new SessionAuth($this->config(), $login);
        $auth->decorate(new HttpRequest('POST', 'https://cloud.example.com/K3Cloud/x.common.kdsvc', '{}'));

        // Same identity, only TLS differs (exactly what ->insecure() produces).
        $rebuilt = $auth->withDependencies($this->config()->withTlsVerification(false), new FakeTransport());

        self::assertTrue($rebuilt->isLoggedIn(), 'live session must survive a non-identity reconfigure');

        $quiet = new FakeTransport(); // if it logged in again, requests would be >0
        $decorated = $rebuilt->decorate(new HttpRequest('POST', 'https://cloud.example.com/K3Cloud/y.common.kdsvc', '{}'));
        self::assertCount(0, $quiet->requests, 'rebase must NOT trigger a second login');
        self::assertSame(['kdsessionid' => 'abc'], $decorated->cookies());
    }

    public function testRebaseDropsSessionWhenCredentialsChange(): void
    {
        $login = new FakeTransport();
        $login->queueResponse(200, '{"LoginResultType":1,"KDSVCSessionId":"abc"}', [
            'Set-Cookie' => ['kdsessionid=abc; path=/'],
        ]);
        $auth = new SessionAuth($this->config(), $login);
        $auth->decorate(new HttpRequest('POST', 'https://cloud.example.com/K3Cloud/x.common.kdsvc', '{}'));

        $other = Config::password('https://cloud.example.com/K3Cloud', 'acct123', 'tester', 'different-pwd');
        $rebuilt = $auth->withDependencies($other, new FakeTransport());

        self::assertFalse($rebuilt->isLoggedIn(), 'a different identity must authenticate afresh');
    }

    public function testSignatureRebaseIsStatelessAndTyped(): void
    {
        $sig = new SignatureAuth(Config::appSignature('https://h/K3Cloud', 'acct', 'user', '204399_' . base64_encode('abcd'), 'sec'));
        $rebuilt = $sig->withDependencies(
            Config::appSignature('https://h/K3Cloud', 'acct', 'user', '204399_' . base64_encode('abcd'), 'sec'),
            new FakeTransport()
        );
        self::assertInstanceOf(SignatureAuth::class, $rebuilt);
    }
}
