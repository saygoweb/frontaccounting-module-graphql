<?php

namespace FA\GraphQL\Tests\Unit\Auth;

use FA\GraphQL\Auth\Guard;
use FA\GraphQL\Error\Forbidden;
use FA\GraphQL\Error\Unauthenticated;
use PHPUnit\Framework\TestCase;

class GuardTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION['wa_current_user']);
    }

    private function signIn(array $areas, bool $loggedIn = true): void
    {
        $_SESSION['wa_current_user'] = new class ($areas, $loggedIn) {
            private array $areas;
            private bool $loggedIn;

            public function __construct(array $areas, bool $loggedIn)
            {
                $this->areas = $areas;
                $this->loggedIn = $loggedIn;
            }

            // Mirrors FrontAccounting's current_user::logged_in()/can_access(),
            // which is what Guard calls; not this module's own naming.
            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
            public function logged_in(): bool
            {
                return $this->loggedIn;
            }

            // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps
            public function can_access(string $area): bool
            {
                return in_array($area, $this->areas, true);
            }
        };
    }

    public function testNobodyIsUnauthenticated(): void
    {
        $this->expectException(Unauthenticated::class);
        Guard::authenticated();
    }

    public function testAUserObjectThatIsNotLoggedInIsUnauthenticated(): void
    {
        $this->signIn(['SA_GRAPHQL'], false);

        $this->expectException(Unauthenticated::class);
        Guard::require('SA_GRAPHQL');
    }

    public function testRequirePasses(): void
    {
        $this->signIn(['SA_GRAPHQL', 'SA_SALESORDER']);

        Guard::require('SA_SALESORDER');
        $this->addToAssertionCount(1);
    }

    public function testRequireNamesTheMissingArea(): void
    {
        $this->signIn(['SA_GRAPHQL']);

        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage('SA_SALESORDER');
        Guard::require('SA_SALESORDER');
    }

    public function testRequireForLooksTheVerbUp(): void
    {
        $this->signIn(['SA_SALESTRANSVIEW']);

        Guard::requireFor(['list' => 'SA_SALESTRANSVIEW', 'create' => 'SA_SALESORDER'], 'list');
        $this->expectException(Forbidden::class);
        Guard::requireFor(['list' => 'SA_SALESTRANSVIEW', 'create' => 'SA_SALESORDER'], 'create');
    }

    public function testAVerbWithNoAreaIsDeniedNotAllowed(): void
    {
        $this->signIn(['SA_GRAPHQL', 'SA_SALESORDER', 'SA_SALESTRANSVIEW']);

        $this->expectException(Forbidden::class);
        Guard::requireFor(['list' => 'SA_SALESTRANSVIEW'], 'delete');
    }

    public function testAnEmptyAreaIsDenied(): void
    {
        $this->signIn(['']);

        $this->expectException(Forbidden::class);
        Guard::requireFor(['list' => ''], 'list');
    }
}
