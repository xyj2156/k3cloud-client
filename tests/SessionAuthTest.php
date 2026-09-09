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
        // 第一个 send() 是 ValidateUser 登录；为其排队一个响应。
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

        // 身份相同，仅 TLS 不同（正是 ->insecure() 产生的效果）。
        $rebuilt = $auth->withDependencies($this->config()->withTlsVerification(false), new FakeTransport());

        self::assertTrue($rebuilt->isLoggedIn(), 'live session must survive a non-identity reconfigure');

        $quiet = new FakeTransport(); // 若它又登录了一次，requests 会 >0
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

    /** 返回一个已登录的 SessionAuth，以及产生该登录的 FakeTransport。 */
    private function loggedInAuth(): SessionAuth
    {
        $transport = new FakeTransport();
        $transport->queueResponse(200, '{"LoginResultType":1,"KDSVCSessionId":"abc"}', [
            'Set-Cookie' => ['kdsessionid=abc; path=/'],
        ]);
        $auth = new SessionAuth($this->config(), $transport);
        $auth->decorate(new HttpRequest('POST', 'https://cloud.example.com/K3Cloud/x.common.kdsvc', '{}'));
        return $auth;
    }

    public function testRealSessionLostPayloadTriggersRelogin(): void
    {
        // 来自生产环境的真实形态：HTTP 200 + IsSuccess=false + 重新登录消息。
        $body = '{"Result":{"ResponseStatus":{"ErrorCode":500,"IsSuccess":false,'
            . '"Errors":[{"FieldName":null,"Message":"会话信息已丢失，请重新登录","DIndex":0}],'
            . '"SuccessEntitys":[],"SuccessMessages":[],"MsgCode":1}}}';

        $auth = $this->loggedInAuth();
        $retry = $auth->shouldRetry(
            new HttpRequest('POST', 'u', '{}'),
            new \K3Cloud\Http\HttpResponse(200, $body)
        );

        self::assertTrue($retry, 'the real session-lost message must trigger a re-login/replay');
        self::assertFalse($auth->isLoggedIn(), 'session dropped so the replay re-authenticates');
    }

    public function testOrdinaryBusinessErrorDoesNotRelogin(): void
    {
        $body = '{"Result":{"ResponseStatus":{"IsSuccess":false,'
            . '"Errors":[{"FieldName":"FNUMBER","Message":"编码已存在，请检查","DIndex":0}]}}}';

        $auth = $this->loggedInAuth();
        $retry = $auth->shouldRetry(
            new HttpRequest('POST', 'u', '{}'),
            new \K3Cloud\Http\HttpResponse(200, $body)
        );

        self::assertFalse($retry, 'a validation error is not a session problem');
        self::assertTrue($auth->isLoggedIn(), 'stays logged in');
    }

    public function testSessionKeywordsInsideSuccessfulDataDoNotRelogin(): void
    {
        // 按消息匹配：这些词出现在返回的行数据里，而非错误信封里，
        // 因此不应被当作会话丢失。
        $auth = $this->loggedInAuth();
        $retry = $auth->shouldRetry(
            new HttpRequest('POST', 'u', '{}'),
            new \K3Cloud\Http\HttpResponse(200, '[["未登录","会话信息已丢失"]]')
        );

        self::assertFalse($retry, 'session words inside returned data must not force a re-login');
        self::assertTrue($auth->isLoggedIn());
    }
}
