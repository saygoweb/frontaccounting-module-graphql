<?php

namespace FA\GraphQL\Tests\Integration;

use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Config;
use FA\GraphQL\Error\Forbidden;
use FA\GraphQL\Error\Unauthenticated;
use FA\GraphQL\Fa\CompanyContext;
use FA\GraphQL\Fa\FaSession;
use FA\GraphQL\Fa\VerifiedIdentity;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class FaSessionTest extends FaTestCase
{
    private function session(): FaSession
    {
        return new FaSession(Config::fromArray(['secret' => '0123456789abcdef0123456789abcdef']));
    }

    private function claims(string $login, int $company = 0): Claims
    {
        return new Claims($company, $login, 'jti', new \DateTimeImmutable('+5 minutes'));
    }

    public function testBootLoadsFrontAccountingWithNobodyLoggedIn(): void
    {
        $session = $this->session();
        $session->boot();
        $session->boot(); // idempotent: the middleware calls it on every request

        $this->assertTrue(\FA\GraphQL\Fa\Bootstrap::isBooted());
        $this->assertTrue(function_exists('install_hooks'));
        $this->assertFalse(isset($_SESSION['wa_current_user']) && $_SESSION['wa_current_user']->logged_in());
        $this->assertSame(PHP_SESSION_NONE, session_status());
    }

    public function testAVerifiedIdentityBecomesARealFrontAccountingUser(): void
    {
        $session = $this->session();
        $session->enter($this->claims('apitest'));

        $user = $_SESSION['wa_current_user'];
        $this->assertTrue($user->logged_in());
        $this->assertSame('apitest', $user->loginname);
        $this->assertSame('API Test', $user->name);
        $this->assertTrue($user->can_access('SA_GRAPHQL'));
        $this->assertTrue($user->can_access('SA_SALESORDER'));
        $this->assertGreaterThan(0, $session->userId());
        $this->assertTrue(CompanyContext::isSet());
        $this->assertSame('0_', CompanyContext::prefix());
    }

    public function testTheFlagIsClearedAfterASuccessfulEnter(): void
    {
        $this->session()->enter($this->claims('apitest'));

        $this->assertFalse(VerifiedIdentity::matches(0, 'apitest'));
    }

    public function testTheFlagIsClearedWhenEnterFails(): void
    {
        try {
            $this->session()->enter($this->claims('nobody'));
            $this->fail('an unknown user was entered');
        } catch (Unauthenticated $e) {
            $this->assertFalse(VerifiedIdentity::matches(0, 'nobody'));
        }
    }

    public function testADeactivatedUserIsRefused(): void
    {
        $this->pdo()->exec("UPDATE 0_users SET inactive = 1 WHERE user_id = 'apitest'");
        try {
            $this->expectException(Unauthenticated::class);
            $this->session()->enter($this->claims('apitest'));
        } finally {
            $this->pdo()->exec("UPDATE 0_users SET inactive = 0 WHERE user_id = 'apitest'");
        }
    }

    public function testAUserWithoutTheApiAreaIsForbidden(): void
    {
        $this->expectException(Forbidden::class);
        $this->session()->enter($this->claims('noapi'));
    }

    public function testAnUnknownCompanyIsRefused(): void
    {
        $this->expectException(Unauthenticated::class);
        $this->session()->enter($this->claims('apitest', 99));
    }

    public function testTheHookAnswersNullWithoutTheFlag(): void
    {
        $this->session()->openCompany(0);
        $hook = new \hooks_graphql();

        $this->assertNull($hook->authenticate('apitest', 'anything'));
        VerifiedIdentity::set(0, 'admin');
        $this->assertNull($hook->authenticate('apitest', 'anything'), 'set for another login');
        VerifiedIdentity::set(1, 'apitest');
        $this->assertNull($hook->authenticate('apitest', 'anything'), 'set for another company');
        VerifiedIdentity::set(0, 'apitest');
        $this->assertTrue($hook->authenticate('apitest', 'anything'));
    }

    public function testPasswordLoginStillChecksThePassword(): void
    {
        $session = $this->session();

        try {
            $session->loginWithPassword(0, 'apitest', 'wrong');
            $this->fail('a wrong password was accepted');
        } catch (Unauthenticated $e) {
            $this->assertSame('The user name or password is incorrect.', $e->getMessage());
        }

        $session->loginWithPassword(0, 'apitest', 'password');
        $this->assertTrue($_SESSION['wa_current_user']->logged_in());
    }

    /**
     * A password login is a login attempt: FrontAccounting's throttle applies as it
     * does in the web UI, which means updating the counters already on file, not
     * replacing them with this attempt's alone.
     */
    public function testPasswordLoginKeepsFrontAccountingsThrottleCounters(): void
    {
        $file = \FA\GraphQL\Fa\Bootstrap::defaultRoot() . '/tmp/faillog.php';
        $original = is_file($file) ? file_get_contents($file) : null;
        file_put_contents(
            $file,
            "<?php\n\$login_faillog = array ('other' => array ('10.1.1.1' => 3, 'last' => 1790301030));\n"
        );
        try {
            $_SERVER['REMOTE_ADDR'] = '10.2.2.2';
            try {
                $this->session()->loginWithPassword(0, 'apitest', 'wrong');
                $this->fail('a wrong password was accepted');
            } catch (Unauthenticated $e) {
                $this->addToAssertionCount(1);
            }

            $login_faillog = [];
            include $file;
            $this->assertSame(['10.1.1.1' => 3, 'last' => 1790301030], $login_faillog['other'] ?? null);
            $mine = array_values(array_filter($login_faillog, function ($entry) {
                return isset($entry['10.2.2.2']);
            }));
            $this->assertCount(1, $mine, 'the failed attempt was counted');
            $this->assertSame(1, $mine[0]['10.2.2.2']);
        } finally {
            if ($original === null) {
                unlink($file);
            } else {
                file_put_contents($file, $original);
            }
        }
    }

    /**
     * spec section 3.2 (revised): FrontAccounting only counts failures and greys out
     * its login button; it never refuses on the server. An API is scriptable, so
     * login refuses with the same single Unauthenticated message, before the
     * password is even checked, while check_faillog() says the request is throttled.
     */
    public function testLoginRefusesWhileThrottledEvenWithTheRightPassword(): void
    {
        $file = \FA\GraphQL\Fa\Bootstrap::defaultRoot() . '/tmp/faillog.php';
        $original = is_file($file) ? file_get_contents($file) : null;
        $_SERVER['REMOTE_ADDR'] = '10.4.4.4';
        // 10 failures (the default login_max_attempts), 'last' now: exceeds the
        // count and is still inside the default 30-second login_delay window.
        file_put_contents(
            $file,
            "<?php\n\$login_faillog = array (0 => array ('10.4.4.4' => 10, 'last' => " . time() . "));\n"
        );
        try {
            $this->expectException(Unauthenticated::class);
            $this->expectExceptionMessage('The user name or password is incorrect.');
            $this->session()->loginWithPassword(0, 'apitest', 'password');
        } finally {
            if ($original === null) {
                unlink($file);
            } else {
                file_put_contents($file, $original);
            }
        }
    }

    public function testPasswordLoginWithoutTheApiAreaIsForbidden(): void
    {
        $this->expectException(Forbidden::class);
        $this->session()->loginWithPassword(0, 'noapi', 'password');
    }

    public function testOtherExtensionsHooksAreInstalled(): void
    {
        $sgwSalesHooks = \FA\GraphQL\Fa\Bootstrap::defaultRoot() . '/modules/sgw_sales/hooks.php';
        if (!is_file($sgwSalesHooks) || getenv('SGW_SALES_ACTIVE') === 'false') {
            $this->markTestSkipped('sgw_sales is not active in this stack.');
        }
        $this->session()->enter($this->claims('apitest'));

        $this->assertArrayHasKey('sgw_sales', $GLOBALS['Hooks']);
        $this->assertArrayHasKey('graphql', $GLOBALS['Hooks']);
    }
}
