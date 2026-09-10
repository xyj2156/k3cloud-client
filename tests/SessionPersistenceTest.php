<?php

declare(strict_types=1);

namespace K3Cloud\Tests;

use K3Cloud\Auth\FileSessionStore;
use K3Cloud\Auth\NullSessionStore;
use K3Cloud\Auth\SessionAuth;
use K3Cloud\Config;
use K3Cloud\Http\HttpRequest;
use K3Cloud\Http\HttpResponse;
use K3Cloud\K3CloudClient;
use PHPUnit\Framework\TestCase;

/**
 * 会话落盘（SessionStore / FileSessionStore）：登录一次、跨进程复用、失效自愈。
 * 全部使用隔离的临时目录 + FakeTransport，不触网也不碰默认目录。
 */
final class SessionPersistenceTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    private function config(): Config
    {
        return Config::password(
            serverUrl: 'https://cloud.example.com/K3Cloud',
            acctId: 'acct123',
            userName: 'tester',
            password: 'p@ssw0rd',
        );
    }

    private function storeDir(): string
    {
        $dir = sys_get_temp_dir() . '/k3cloud-sessions-test-' . uniqid('', true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function apiRequest(): HttpRequest
    {
        return new HttpRequest('POST', 'https://cloud.example.com/K3Cloud/x.common.kdsvc', '{}');
    }

    /** 排入一次成功的 ValidateUser 响应（会话 id 可指定）。 */
    private function queueLogin(FakeTransport $transport, string $sessionId = 'abc'): void
    {
        $transport->queueResponse(200, '{"LoginResultType":1,"KDSVCSessionId":"' . $sessionId . '"}', [
            'Set-Cookie' => ['kdsessionid=' . $sessionId . '; path=/; HttpOnly'],
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            foreach ((array) glob($dir . '/*') as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
        $this->tempDirs = [];
    }

    /* ------------------------------------------------------------------
     |  SessionAuth 层
     * ---------------------------------------------------------------- */

    public function testSuccessfulLoginIsPersistedToStore(): void
    {
        $store = new FileSessionStore($this->storeDir());
        $transport = new FakeTransport();
        $this->queueLogin($transport);

        $auth = new SessionAuth($this->config(), $transport, $store);
        self::assertFalse($auth->isLoggedIn(), 'no eager login');

        $auth->decorate($this->apiRequest());

        $files = glob($store->directory() . '/session-*.json');
        self::assertCount(1, $files, 'login persisted exactly one session file');

        $data = json_decode((string) file_get_contents($files[0]), true);
        self::assertSame(1, $data['version']);
        self::assertSame(['kdsessionid' => 'abc'], $data['cookies']);
        self::assertIsInt($data['saved_at']);
    }

    public function testPersistedSessionIsRestoredWithoutSecondLogin(): void
    {
        $store = new FileSessionStore($this->storeDir());

        $first = new FakeTransport();
        $this->queueLogin($first, 'session-42');
        $auth1 = new SessionAuth($this->config(), $first, $store);
        $auth1->decorate($this->apiRequest());

        // 模拟"下一个进程"：全新实例 + 全新传输层。
        $quiet = new FakeTransport();
        $auth2 = new SessionAuth($this->config(), $quiet, $store);

        self::assertTrue($auth2->isLoggedIn(), 'restored session is considered live');

        $decorated = $auth2->decorate($this->apiRequest());
        self::assertCount(0, $quiet->requests, 'must NOT send a ValidateUser request');
        self::assertSame(['kdsessionid' => 'session-42'], $decorated->cookies());
    }

    public function testExpiredPersistedSessionSelfHealsViaExistingRetryPath(): void
    {
        $dir = $this->storeDir();

        // 进程 1：正常登录并落盘（会话 id = stale）。
        $t1 = new FakeTransport();
        $this->queueLogin($t1, 'stale');
        (new K3CloudClient($this->config(), $t1))->withSessionStorePath($dir)
            ->save('BD_Currency', ['Model' => ['FNUMBER' => 'X']]);

        // 进程 2：重放的旧会话被服务端宣告丢失 → 自动重登（fresh）→ 重放业务请求。
        $lost = '{"Result":{"ResponseStatus":{"IsSuccess":false,'
            . '"Errors":[{"FieldName":null,"Message":"会话信息已丢失，请重新登录","DIndex":0}]}}}';
        $t2 = new FakeTransport();
        $t2->queueResponse(200, $lost);        // save #1（旧会话）
        $this->queueLogin($t2, 'fresh');       // 透明重登
        $t2->queueResponse(200, '{"ok":true}'); // save 重放

        $api2 = (new K3CloudClient($this->config(), $t2))->withSessionStorePath($dir);
        $api2->save('BD_Currency', ['Model' => ['FNUMBER' => 'X']]);

        self::assertCount(3, $t2->requests, 'save(lost) -> login -> save(replay)');
        self::assertStringContainsString('DynamicFormService.Save', $t2->requests[0]->url);
        self::assertStringContainsString('AuthService.ValidateUser', $t2->requests[1]->url);
        self::assertStringContainsString('DynamicFormService.Save', $t2->requests[2]->url);

        $data = json_decode((string) file_get_contents(glob($dir . '/session-*.json')[0]), true);
        self::assertSame('fresh', $data['cookies']['kdsessionid'], 'store updated to the fresh session');
    }

    public function testInvalidateForgetsPersistedSession(): void
    {
        $dir = $this->storeDir();
        $store = new FileSessionStore($dir);

        $transport = new FakeTransport();
        $this->queueLogin($transport);
        $auth = new SessionAuth($this->config(), $transport, $store);
        $auth->decorate($this->apiRequest());
        self::assertCount(1, glob($dir . '/session-*.json'));

        $auth->invalidate();
        self::assertCount(0, glob($dir . '/session-*.json'), 'dead session id must not linger on disk');
    }

    public function testCorruptedStoreEntryIsIgnored(): void
    {
        $dir = $this->storeDir();
        mkdir($dir, 0700, true);
        file_put_contents($dir . '/session-0000000000000000000000000000000000000000.json', 'not json {{');

        $transport = new FakeTransport();
        $auth = new SessionAuth($this->config(), $transport, new FileSessionStore($dir));

        self::assertFalse($auth->isLoggedIn(), 'garbage must not masquerade as a session');
        $this->queueLogin($transport);
        $auth->decorate($this->apiRequest()); // 照常登录一次
        self::assertStringContainsString('AuthService.ValidateUser', $transport->lastRequest()->url);
    }

    public function testDifferentCredentialsUseSeparateEntries(): void
    {
        $store = new FileSessionStore($this->storeDir());

        $t1 = new FakeTransport();
        $this->queueLogin($t1, 'one');
        (new SessionAuth($this->config(), $t1, $store))->decorate($this->apiRequest());

        $t2 = new FakeTransport();
        $this->queueLogin($t2, 'two');
        $other = Config::password('https://cloud.example.com/K3Cloud', 'acct123', 'tester', 'other-pwd');
        $auth2 = new SessionAuth($other, $t2, $store);

        self::assertFalse($auth2->isLoggedIn(), 'another identity must not inherit the stored session');
        self::assertCount(1, glob($store->directory() . '/session-*.json'), 'load for the new key misses');
    }

    public function testNullStoreKeepsEverythingInMemory(): void
    {
        $transport = new FakeTransport();
        $this->queueLogin($transport);
        $auth = new SessionAuth($this->config(), $transport, new NullSessionStore());
        $auth->decorate($this->apiRequest());
        $auth->invalidate();

        $quiet = new FakeTransport();
        self::assertFalse((new SessionAuth($this->config(), $quiet, new NullSessionStore()))->isLoggedIn());
        self::assertCount(0, $quiet->requests);
    }

    /* ------------------------------------------------------------------
     |  K3CloudClient 层（默认开启 + 链式选项）
     * ---------------------------------------------------------------- */

    public function testClientPersistsLoginByDefaultAndSecondClientSkipsLogin(): void
    {
        $dir = $this->storeDir();

        $t1 = new FakeTransport();
        $this->queueLogin($t1, 'client-session');
        $api1 = (new K3CloudClient($this->config(), $t1))->withSessionStorePath($dir);
        $api1->save('BD_Currency', ['Model' => ['FNUMBER' => 'X']]);
        self::assertCount(2, $t1->requests, 'login then save');
        self::assertCount(1, glob($dir . '/session-*.json'));

        // 第二个"进程"：另一台客户端、另一个传输层。
        $t2 = new FakeTransport();
        $api2 = (new K3CloudClient($this->config(), $t2))->withSessionStorePath($dir);
        $api2->save('BD_Currency', ['Model' => ['FNUMBER' => 'X']]);
        self::assertCount(1, $t2->requests, 'only the save; login reused');
        self::assertStringContainsString('DynamicFormService.Save', $t2->requests[0]->url);
    }

    public function testWithoutSessionPersistenceNeverTouchesDisk(): void
    {
        $dir = $this->storeDir();

        $transport = new FakeTransport();
        $this->queueLogin($transport);
        $api = (new K3CloudClient($this->config(), $transport))
            ->withSessionStorePath($dir)
            ->withoutSessionPersistence();
        $api->save('BD_Currency', ['Model' => ['FNUMBER' => 'X']]);

        self::assertCount(0, (array) glob($dir . '/*'), 'nothing written when persistence is off');

        // 同凭据的另一台客户端仍需各自登录。
        $second = new FakeTransport();
        $this->queueLogin($second);
        (new K3CloudClient($this->config(), $second))
            ->withoutSessionPersistence()
            ->save('BD_Currency', ['Model' => ['FNUMBER' => 'X']]);
        self::assertCount(2, $second->requests, 'login is per-process again');
    }

    public function testRebaseChainKeepsStoreAndSession(): void
    {
        $dir = $this->storeDir();

        $transport = new FakeTransport();
        $this->queueLogin($transport, 'rebase-me');
        $api = (new K3CloudClient($this->config(), $transport))->withSessionStorePath($dir);
        $api->save('BD_Currency', ['Model' => ['FNUMBER' => 'X']]);

        // ->insecure() 重建传输层与客户端，会话与存储都应带过去，不产生新登录。
        $quiet = new FakeTransport();
        $rebased = (new K3CloudClient($this->config()->withTlsVerification(false), $quiet))
            ->withSessionStorePath($dir);
        $rebased->save('BD_Currency', ['Model' => ['FNUMBER' => 'X']]);

        self::assertCount(1, $quiet->requests, 'restored-from-disk session survives client rebuild');
    }

    public function testSessionStoreOptionsAreHarmlessNoopsForSignatureMode(): void
    {
        $api = K3CloudClient::appSignature('https://h/K3Cloud', 'acct', 'user', '204399_' . base64_encode('abcd'), 'sec')
            ->withSessionStorePath($this->storeDir())
            ->withoutSessionPersistence();

        self::assertInstanceOf(K3CloudClient::class, $api);
        self::assertTrue($api->config()->verifyTls, 'config untouched by session-store options');

        // 签名模式照常工作：无登录、无落盘，只有一个带签名头的业务请求。
        $transport = new FakeTransport();
        (new K3CloudClient($api->config(), $transport))->save('BD_Currency', ['Model' => []]);
        self::assertCount(1, $transport->requests);
        self::assertStringNotContainsString('ValidateUser', $transport->requests[0]->url);
    }
}
