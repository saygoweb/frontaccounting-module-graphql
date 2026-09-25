<?php

namespace FA\GraphQL\Tests\Integration;

use FA\GraphQL\Auth\Model\UserModel;
use FA\GraphQL\Fa\CompanyContext;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class UserModelTest extends FaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CompanyContext::set(0, $GLOBALS['db_connections'][0]);
    }

    public function testFindById(): void
    {
        $id = (int) $this->pdo()->query("SELECT id FROM 0_users WHERE user_id = 'apitest'")->fetchColumn();

        $user = UserModel::findById($this->pdo(), $id);

        $this->assertSame('apitest', $user->login);
        $this->assertSame('API Test', $user->realName);
        $this->assertFalse($user->inactive, 'inactive is a boolean (spec §4.4 transformers)');
    }

    public function testAnInactiveUserReadsAsTrue(): void
    {
        $this->pdo()->exec("UPDATE 0_users SET inactive = 1 WHERE user_id = 'apitest'");
        try {
            $id = (int) $this->pdo()->query("SELECT id FROM 0_users WHERE user_id = 'apitest'")->fetchColumn();

            $this->assertTrue(UserModel::findById($this->pdo(), $id)->inactive);
        } finally {
            $this->pdo()->exec("UPDATE 0_users SET inactive = 0 WHERE user_id = 'apitest'");
        }
    }

    public function testUnknownIdIsNull(): void
    {
        $this->assertNull(UserModel::findById($this->pdo(), 31999));
    }
}
