<?php

namespace FA\GraphQL\Tests\Unit\Auth\Model;

use FA\GraphQL\Auth\Model\RefreshTokenModel;
use FA\GraphQL\Auth\Model\UserModel;
use FA\GraphQL\Fa\CompanyContext;
use PHPUnit\Framework\TestCase;

/**
 * What `anorm-graphql make` does to every model it scans: construct it with a PDO
 * that cannot query, with no company selected. These two are never scanned, but
 * follow the same conventions (spec §4.4) so nothing about them is special.
 */
class ModelConstructionTest extends TestCase
{
    protected function tearDown(): void
    {
        CompanyContext::reset();
    }

    private function nullPdo(): \PDO
    {
        return new class extends \PDO {
            public function __construct()
            {
            }

            #[\ReturnTypeWillChange]
            public function setAttribute($attribute, $value)
            {
                return true;
            }
        };
    }

    /**
     * @dataProvider models
     */
    public function testConstructsWithoutADatabase(string $class, string $table): void
    {
        $model = new $class($this->nullPdo());

        $this->assertSame('0_' . $table, $model->_mapper->table);
    }

    /**
     * @dataProvider models
     */
    public function testTableFollowsTheCompanyPrefix(string $class, string $table): void
    {
        CompanyContext::set(2, [
            'host' => 'h', 'dbuser' => 'u', 'dbpassword' => 'p', 'dbname' => 'd', 'tbpref' => '2_',
        ]);

        $model = new $class($this->nullPdo());

        $this->assertSame('2_' . $table, $model->_mapper->table);
    }

    public function models(): array
    {
        return [[RefreshTokenModel::class, 'graphql_refresh_token'], [UserModel::class, 'users']];
    }

    public function testUserModelCannotCarryAPassword(): void
    {
        $this->assertFalse(property_exists(UserModel::class, 'password'));
        $this->assertNotContains('password', (new UserModel($this->nullPdo()))->_mapper->map);
    }

    /**
     * @dataProvider models
     */
    public function testInternalModelsAreOutsideTheScannedFolder(string $class): void
    {
        $scanned = dirname(__DIR__, 4) . '/src/Model/';
        $file = (string) (new \ReflectionClass($class))->getFileName();

        $this->assertStringStartsNotWith($scanned, $file, "$class is in the folder bin/generate scans");
        $this->assertStringStartsNotWith(
            'FA\\GraphQL\\Model\\',
            $class,
            "$class is in the namespace bin/generate scans"
        );
    }
}
