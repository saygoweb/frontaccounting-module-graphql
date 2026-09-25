<?php

namespace FA\GraphQL\Tests\Http;

use FA\GraphQL\Auth\TokenService;
use FA\GraphQL\Config;
use Lcobucci\Clock\FrozenClock;
use PHPUnit\Framework\TestCase;

class AuthFlowTest extends TestCase
{
    use GraphQLClient;

    private const REFRESH = 'mutation ($t: String!) { tokenRefresh(refreshToken: $t) { accessToken refreshToken } }';

    private function code(array $response): ?string
    {
        return $response['body']['errors'][0]['extensions']['code'] ?? null;
    }

    public function testTheWholeFlow(): void
    {
        $pair = $this->login();
        $this->assertSame(900, $pair['expiresIn']);

        $me = $this->gql('{ me { login name company companyName areas } }', [], $pair['accessToken']);
        $this->assertSame(200, $me['status']);
        $this->assertSame('apitest', $me['body']['data']['me']['login']);
        $this->assertContains('SA_GRAPHQL', $me['body']['data']['me']['areas']);

        $refreshed = $this->gql(self::REFRESH, ['t' => $pair['refreshToken']]);
        $new = $refreshed['body']['data']['tokenRefresh'];
        $this->assertNotSame($pair['refreshToken'], $new['refreshToken']);
        $refreshedMe = $this->gql('{ me { login } }', [], $new['accessToken']);
        $this->assertSame('apitest', $refreshedMe['body']['data']['me']['login']);

        // The old refresh token again: refused, and it takes the chain with it.
        $this->assertSame('UNAUTHENTICATED', $this->code($this->gql(self::REFRESH, ['t' => $pair['refreshToken']])));
        $this->assertSame('UNAUTHENTICATED', $this->code($this->gql(self::REFRESH, ['t' => $new['refreshToken']])));

        // Sign in again, then revoke.
        $again = $this->login();
        $revoked = $this->gql(
            'mutation ($t: String) { tokenRevoke(refreshToken: $t) }',
            ['t' => $again['refreshToken']],
            $again['accessToken']
        );
        $this->assertTrue($revoked['body']['data']['tokenRevoke']);
        $this->assertSame('UNAUTHENTICATED', $this->code($this->gql(self::REFRESH, ['t' => $again['refreshToken']])));
    }

    public function testWrongPassword(): void
    {
        $response = $this->gql('mutation { login(user: "apitest", password: "nope") { accessToken } }');

        $this->assertSame(200, $response['status']);
        $this->assertSame('UNAUTHENTICATED', $this->code($response));
        $this->assertNull($response['body']['data'] ?? null);
    }

    public function testAUserWithoutTheApiAreaIsForbidden(): void
    {
        $response = $this->gql('mutation { login(user: "noapi", password: "password") { accessToken } }');

        $this->assertSame('FORBIDDEN', $this->code($response));
    }

    public function testNoTokenIsUnauthenticatedForMeButNotForApiVersion(): void
    {
        $this->assertSame('UNAUTHENTICATED', $this->code($this->gql('{ me { login } }')));
        $this->assertSame('0.1.0', $this->gql('{ apiVersion }')['body']['data']['apiVersion']);
    }

    public function testTamperedTokenIs401(): void
    {
        $token = $this->login()['accessToken'];
        $response = $this->gql('{ me { login } }', [], substr($token, 0, -3) . 'AAA');

        $this->assertSame(401, $response['status']);
        $this->assertSame('UNAUTHENTICATED', $this->code($response));
    }

    public function testExpiredTokenIs401(): void
    {
        $response = $this->gql('{ me { login } }', [], $this->mint('apitest', 0, '2020-01-01 00:00:00'));

        $this->assertSame(401, $response['status']);
    }

    public function testATokenForACompanyThatDoesNotExistIs401(): void
    {
        $response = $this->gql('{ me { login } }', [], $this->mint('apitest', 99, 'now'));

        $this->assertSame(401, $response['status']);
    }

    public function testATokenForAUserWhoDoesNotExistIs401(): void
    {
        $response = $this->gql('{ me { login } }', [], $this->mint('nobody', 0, 'now'));

        $this->assertSame(401, $response['status']);
    }

    public function testATokenForAUserWithoutTheApiAreaIs403(): void
    {
        $response = $this->gql('{ me { login } }', [], $this->mint('noapi', 0, 'now'));

        $this->assertSame(403, $response['status']);
    }

    public function testDepthLimitIsEnforced(): void
    {
        // 12 nested `ofType`s: webonyx counts the leaf too, so this is depth 15
        // against the default max_depth of 12 — comfortably over, not just at the
        // edge (8 nestings, as in an earlier draft of this test, is depth 11: under
        // the limit, so it never failed).
        $query = '{ __schema { types { fields { type { '
            . str_repeat('ofType { ', 12) . 'name ' . str_repeat('} ', 12) . '} } } } }';

        $response = $this->gql($query);

        $this->assertStringContainsString('depth', strtolower($response['body']['errors'][0]['message']));
    }

    /**
     * FrontAccounting's login form needs a CSRF `_token`, and `preventHijacking()`
     * (includes/session.inc; session_utils.inc in the fork) resets the session — silently discarding that
     * token — the moment the User-Agent on the POST differs from the one that
     * fetched the form. So the form is fetched and posted with the same
     * User-Agent and the same session cookie, and a positive control (the right
     * password) is checked too: without it, a broken CSRF/session dance would
     * make the negative assertion pass for the wrong reason.
     */
    public function testTheWebLoginStillChecksPasswords(): void
    {
        $base = dirname($this->url(), 2) . '/index.php';

        [$page, $cookie] = $this->webGet($base);
        $wrong = $this->webPost($base, $cookie, $this->formToken($page), [
            'user_name_entry_field' => 'apitest', 'password' => 'wrong', 'company_login_name' => 0,
        ]);
        $this->assertStringNotContainsString('Logout', $wrong[0]);

        $right = $this->webPost($base, $wrong[1], $this->formToken($wrong[0]), [
            'user_name_entry_field' => 'apitest', 'password' => 'password', 'company_login_name' => 0,
        ]);
        $this->assertStringContainsString('Logout', $right[0], $right[0]);
    }

    /**
     * @return array{0: string, 1: string} the page, and the Cookie header for the next request
     */
    private function webGet(string $url): array
    {
        return $this->webRequest('GET', $url, '', null);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{0: string, 1: string} the page, and the Cookie header for the next request
     */
    private function webPost(string $url, string $cookie, string $token, array $fields): array
    {
        return $this->webRequest('POST', $url, $cookie, http_build_query($fields + ['_token' => $token]));
    }

    /**
     * FrontAccounting regenerates the session id on a successful login (and,
     * independently, at random on any request — SessionManager::sessionStart()),
     * each time as a redirect carrying a fresh `Set-Cookie`. `file_get_contents`'s
     * own redirect-following reuses the *original* request's headers, so it would
     * follow such a redirect with the now-stale cookie and land back on the old,
     * logged-out session. Redirects are therefore followed by hand here, refreshing
     * the cookie from each hop's own response before the next.
     *
     * @return array{0: string, 1: string} the page, and the Cookie header for the next request
     */
    private function webRequest(string $method, string $url, string $cookie, ?string $body, int $hops = 5): array
    {
        $header = "User-Agent: fa-graphql-tests\r\n";
        if ($cookie !== '') {
            $header .= "Cookie: $cookie\r\n";
        }
        if ($body !== null) {
            $header .= "Content-Type: application/x-www-form-urlencoded\r\n";
        }
        $options = [
            'method' => $method, 'header' => $header, 'ignore_errors' => true,
            'timeout' => 20, 'follow_location' => 0,
        ];
        if ($body !== null) {
            $options['content'] = $body;
        }
        $page = (string) file_get_contents($url, false, stream_context_create(['http' => $options]));
        $headers = $http_response_header ?? [];
        $cookie = $this->cookieFrom($headers, $cookie);

        preg_match('#^HTTP/\S+ (\d{3})#', $headers[0] ?? '', $m);
        $status = (int) ($m[1] ?? 0);
        if ($status >= 300 && $status < 400 && $hops > 0) {
            foreach ($headers as $h) {
                if (stripos($h, 'Location:') === 0) {
                    $location = trim(substr($h, 9));
                    if ($location !== '' && $location[0] === '/') {
                        $parts = parse_url($url);
                        $location = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? '')
                            . (isset($parts['port']) ? ':' . $parts['port'] : '') . $location;
                    }

                    return $this->webRequest('GET', $location, $cookie, null, $hops - 1);
                }
            }
        }

        return [$page, $cookie];
    }

    /**
     * @param array<int, string> $headers
     */
    private function cookieFrom(array $headers, string $fallback = ''): string
    {
        foreach ($headers as $header) {
            if (stripos($header, 'Set-Cookie:') === 0) {
                $fallback = explode(';', trim(substr($header, 11)), 2)[0];
            }
        }

        return $fallback;
    }

    private function formToken(string $page): string
    {
        preg_match('/name="_token" value="([^"]+)"/', $page, $m);
        $this->assertNotEmpty($m[1] ?? null, 'no CSRF token in the login form: ' . substr($page, 0, 300));

        return $m[1];
    }

    /**
     * A token signed with the stack's own secret, at a time of our choosing.
     */
    private function mint(string $login, int $company, string $when): string
    {
        $values = require dirname(__DIR__, 2) . '/config_graphql.php';
        $clock = new FrozenClock(new \DateTimeImmutable($when, new \DateTimeZone('UTC')));

        return (new TokenService(Config::fromArray($values), $clock))->issueAccess($company, $login);
    }
}
