<?php

namespace FA\GraphQL\Tests\Integration;

use FA\GraphQL\Config;
use FA\GraphQL\Error\BadInput;
use FA\GraphQL\Error\Forbidden;
use FA\GraphQL\Error\Unauthenticated;
use FA\GraphQL\RequestInfo;
use FA\GraphQL\Type\Auth\AuthMutations;
use FA\GraphQL\Type\Viewer\ViewerType;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AuthMutationsTest extends FaTestCase
{
    private function container(bool $https = false, bool $allowInsecure = true): \DI\Container
    {
        $config = Config::fromArray([
            'secret' => '0123456789abcdef0123456789abcdef',
            'allow_insecure_login' => $allowInsecure,
        ]);
        $factory = require dirname(__DIR__, 2) . '/container.php';

        return $factory($config, new RequestInfo($https, 'phpunit 127.0.0.1'));
    }

    protected function tearDown(): void
    {
        if (isset($GLOBALS['db_connections'])) {
            $sql = 'DELETE t FROM 0_graphql_refresh_token t JOIN 0_users u ON u.id = t.user_id'
                . " WHERE u.user_id IN ('apitest', 'noapi')";
            $this->pdo()->exec($sql);
        }
    }

    public function testLoginIssuesAPairAndMeDescribesTheUser(): void
    {
        $container = $this->container();
        $payload = $container->get(AuthMutations::class)
            ->resolveLogin(null, ['company' => 0, 'user' => 'apitest', 'password' => 'password']);

        $this->assertSame(900, $payload['expiresIn']);
        $this->assertSame(2, substr_count($payload['accessToken'], '.'));
        $this->assertStringStartsWith('0.', $payload['refreshToken']);

        $me = $container->get(ViewerType::class)->resolveMe();
        $this->assertSame('apitest', $me['login']);
        $this->assertSame('API Test', $me['name']);
        $this->assertSame(0, $me['company']);
        $this->assertNotSame('', $me['companyName']);
        $this->assertContains('SA_GRAPHQL', $me['areas']);
        $this->assertContains('SA_SALESORDER', $me['areas']);
    }

    public function testWrongPasswordAndUnknownUserGetTheSameMessage(): void
    {
        $messages = [];
        foreach ([['apitest', 'wrong'], ['nobody', 'password']] as $pair) {
            try {
                $this->container()->get(AuthMutations::class)
                    ->resolveLogin(null, ['company' => 0, 'user' => $pair[0], 'password' => $pair[1]]);
                $this->fail('accepted');
            } catch (Unauthenticated $e) {
                $messages[] = $e->getMessage();
            }
        }

        $this->assertSame($messages[0], $messages[1]);
    }

    public function testLoginWithoutTheApiAreaIsForbidden(): void
    {
        $this->expectException(Forbidden::class);
        $this->container()->get(AuthMutations::class)
            ->resolveLogin(null, ['company' => 0, 'user' => 'noapi', 'password' => 'password']);
    }

    public function testLoginIsRefusedOverPlainHttpUnlessAllowed(): void
    {
        $this->expectException(BadInput::class);
        $this->container(false, false)->get(AuthMutations::class)
            ->resolveLogin(null, ['company' => 0, 'user' => 'apitest', 'password' => 'password']);
    }

    public function testLoginOverHttpsNeedsNoAllowance(): void
    {
        $payload = $this->container(true, false)->get(AuthMutations::class)
            ->resolveLogin(null, ['company' => 0, 'user' => 'apitest', 'password' => 'password']);

        $this->assertArrayHasKey('accessToken', $payload);
    }

    public function testRefreshRotatesAndTheOldTokenThenKillsTheChain(): void
    {
        $auth = $this->container()->get(AuthMutations::class);
        $first = $auth->resolveLogin(null, ['company' => 0, 'user' => 'apitest', 'password' => 'password']);

        $second = $auth->resolveTokenRefresh(null, ['refreshToken' => $first['refreshToken']]);
        $this->assertNotSame($first['refreshToken'], $second['refreshToken']);

        try {
            $auth->resolveTokenRefresh(null, ['refreshToken' => $first['refreshToken']]);
            $this->fail('a rotated token was accepted');
        } catch (Unauthenticated $e) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(Unauthenticated::class);
        $auth->resolveTokenRefresh(null, ['refreshToken' => $second['refreshToken']]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rowsOf(int $userId): array
    {
        $statement = $this->pdo()->prepare(
            'SELECT id, revoked_at, replaced_by FROM 0_graphql_refresh_token WHERE user_id = ? ORDER BY id'
        );
        $statement->execute([$userId]);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Spec §3.4 step 4 before step 5: refused, and the token is neither used up
     * nor replaced by a live successor nobody holds.
     */
    public function testRefreshForADeactivatedUserIsRefusedAndNotRotated(): void
    {
        $auth = $this->container()->get(AuthMutations::class);
        $pair = $auth->resolveLogin(null, ['company' => 0, 'user' => 'apitest', 'password' => 'password']);
        $userId = (int) $this->pdo()->query("SELECT id FROM 0_users WHERE user_id = 'apitest'")->fetchColumn();
        $before = $this->rowsOf($userId);
        $this->pdo()->exec("UPDATE 0_users SET inactive = 1 WHERE user_id = 'apitest'");

        try {
            $auth->resolveTokenRefresh(null, ['refreshToken' => $pair['refreshToken']]);
            $this->fail('refreshed for a deactivated user');
        } catch (Unauthenticated $e) {
            $this->assertSame($before, $this->rowsOf($userId));
        } finally {
            $this->pdo()->exec("UPDATE 0_users SET inactive = 0 WHERE user_id = 'apitest'");
        }
    }

    public function testRefreshForADeletedUserIsRefusedAndNotRotated(): void
    {
        $deleted = 31999;
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->pdo()->prepare(
            'INSERT INTO 0_graphql_refresh_token (user_id, token_hash, issued_at, expires_at, client)'
            . " VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL 1 DAY, 'phpunit')"
        )->execute([$deleted, hash('sha256', $secret)]);
        $before = $this->rowsOf($deleted);

        try {
            $this->container()->get(AuthMutations::class)
                ->resolveTokenRefresh(null, ['refreshToken' => '0.' . $secret]);
            $this->fail('refreshed for a deleted user');
        } catch (Unauthenticated $e) {
            $this->assertSame('The refresh token is not valid.', $e->getMessage());
            $this->assertSame($before, $this->rowsOf($deleted));
        } finally {
            $this->pdo()->exec('DELETE FROM 0_graphql_refresh_token WHERE user_id = ' . $deleted);
        }
    }

    public function testRefreshForAnUnknownCompanyIsRefused(): void
    {
        $this->expectException(Unauthenticated::class);
        $this->container()->get(AuthMutations::class)
            ->resolveTokenRefresh(null, ['refreshToken' => '99.' . str_repeat('A', 43)]);
    }

    public function testRevokeOneAndRevokeAll(): void
    {
        $auth = $this->container()->get(AuthMutations::class);
        $a = $auth->resolveLogin(null, ['company' => 0, 'user' => 'apitest', 'password' => 'password']);
        $b = $auth->resolveLogin(null, ['company' => 0, 'user' => 'apitest', 'password' => 'password']);

        $this->assertTrue($auth->resolveTokenRevoke(null, ['refreshToken' => $a['refreshToken']]));
        $this->assertFalse($auth->resolveTokenRevoke(null, ['refreshToken' => $a['refreshToken']]));
        $this->assertTrue($auth->resolveTokenRevoke(null, []));
        $this->assertFalse($auth->resolveTokenRevoke(null, []));

        $this->expectException(Unauthenticated::class);
        $auth->resolveTokenRefresh(null, ['refreshToken' => $b['refreshToken']]);
    }

    /**
     * A malformed token is refused the same way any other token the caller does
     * not own is: revoke reports success or failure, never a parse error.
     */
    public function testRevokeOfAMalformedTokenIsFalseNotAnError(): void
    {
        $auth = $this->container()->get(AuthMutations::class);
        $auth->resolveLogin(null, ['company' => 0, 'user' => 'apitest', 'password' => 'password']);

        $this->assertFalse($auth->resolveTokenRevoke(null, ['refreshToken' => 'not-a-token']));
    }

    public function testRevokeNeedsAnIdentity(): void
    {
        $this->expectException(Unauthenticated::class);
        $this->container()->get(AuthMutations::class)->resolveTokenRevoke(null, []);
    }

    public function testMeNeedsAnIdentity(): void
    {
        $this->expectException(Unauthenticated::class);
        $this->container()->get(ViewerType::class)->resolveMe();
    }
}
