<?php

namespace FA\GraphQL\Tests\Integration\Extension;

use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Config;
use FA\GraphQL\Extension\Extensions;
use FA\GraphQL\Fa\Bootstrap;
use FA\GraphQL\RequestInfo;
use FA\GraphQL\SessionGate;
use FA\GraphQL\Tests\Integration\FaTestCase;
use FA\GraphQL\Tests\Support\Extension\FakeExtension;
use FA\GraphQL\Tests\Support\Extension\FakeHooks;
use GraphQL\GraphQL;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ExtensionDiscoveryTest extends FaTestCase
{
    private \DI\Container $container;

    protected function setUp(): void
    {
        parent::setUp();
        $config = Config::fromArray([
            'secret' => '0123456789abcdef0123456789abcdef',
            'fa_root' => Bootstrap::defaultRoot(),
        ]);
        $factory = require dirname(__DIR__, 3) . '/container.php';
        $this->container = $factory($config, new RequestInfo(false, 'phpunit 127.0.0.1'));
    }

    private function enter(): void
    {
        $gate = $this->container->get(SessionGate::class);
        $gate->boot();
        $gate->enter(new Claims(0, 'apitest', 'extension-test', new \DateTimeImmutable('+5 minutes')));
    }

    private static function hello(): FakeExtension
    {
        return new FakeExtension('fake_hello', ['query' => ['fakeHello' => [
            'type' => Type::string(),
            'resolve' => static function (): string {
                return 'hi';
            },
        ]]]);
    }

    public function testAnExtensionContributesOnlyWhereItsHookIsInstalled(): void
    {
        $this->enter();
        // install_hooks() ran for company 0 in enter(); fake_hello is not active there.
        $this->assertNotContains('fake_hello', $this->container->get(Extensions::class)->loaded()->names());

        // A second request's container, where the company's hooks include it.
        $this->setUp();
        $this->enter();
        $GLOBALS['Hooks']['fake_hello'] = new FakeHooks([self::hello()]);
        $schema = $this->container->get(Schema::class);

        $result = GraphQL::executeQuery($schema, '{ fakeHello }', null, $this->container)->toArray();
        $this->assertSame('hi', $result['data']['fakeHello']);
    }

    public function testWithNoCompanyOpenNothingIsRegisteredAndNothingIsCached(): void
    {
        $this->container->get(SessionGate::class)->boot();
        $extensions = $this->container->get(Extensions::class);
        $this->assertSame([], $extensions->loaded()->names());

        $gate = $this->container->get(SessionGate::class);
        $gate->enter(new Claims(0, 'apitest', 'extension-test', new \DateTimeImmutable('+5 minutes')));
        $GLOBALS['Hooks']['fake_hello'] = new FakeHooks([self::hello()]);

        $this->assertContains('fake_hello', $extensions->loaded()->names());
    }

    public function testAHookThatThrowsIsLoggedAndOthersStillRegister(): void
    {
        $this->enter();
        $GLOBALS['Hooks']['fake_broken'] = new FakeHooks([], true);
        $GLOBALS['Hooks']['fake_hello'] = new FakeHooks([self::hello()]);
        $log = [];
        $extensions = new Extensions(
            $this->container,
            $this->container->get(\FA\GraphQL\Fa\FaSession::class),
            function (string $line) use (&$log): void {
                $log[] = $line;
            }
        );

        $this->assertSame(['fake_hello'], array_values(array_filter(
            $extensions->loaded()->names(),
            static function (string $n): bool {
                return strpos($n, 'fake_') === 0;
            }
        )));
        $this->assertStringContainsString('fake_broken', implode("\n", $log));
        $this->assertStringContainsString('hook boom', implode("\n", $log));
    }

    public function testTheCoreSchemaIsUntouchedWhenNoExtensionAddsRootFields(): void
    {
        $this->enter();
        // Only the fakes are removed: a real extension (sgw_sales, from Task 5) may add
        // root fields, and then the assembled schema is not the ApiSchema instance.
        $loaded = $this->container->get(Extensions::class)->loaded();
        if ($loaded->queryFields() !== [] || $loaded->mutationFields() !== []) {
            $this->assertNotInstanceOf(\FA\GraphQL\ApiSchema::class, $this->container->get(Schema::class));

            return;
        }

        $this->assertInstanceOf(\FA\GraphQL\ApiSchema::class, $this->container->get(Schema::class));
    }

    public function testTypeAndInputContributionsAppearOnTheSalesOrderTypes(): void
    {
        $this->enter();
        $GLOBALS['Hooks']['fake_note'] = new FakeHooks([new FakeExtension('fake_note', [
            'types' => ['SalesOrderType' => ['fakeNote' => [
                'type' => Type::string(),
                'resolve' => static function (): string {
                    return 'noted';
                },
            ]]],
            'inputs' => [
                'SalesOrderCreateInput' => ['fakeNote' => ['type' => Type::string()]],
                'SalesOrderUpdateInput' => ['fakeNote' => ['type' => Type::string()]],
            ],
        ])]);
        $schema = $this->container->get(Schema::class);
        $schema->assertValid();

        foreach (['SalesOrderType', 'SalesOrderCreateInput', 'SalesOrderUpdateInput'] as $name) {
            $type = $schema->getType($name);
            $this->assertNotNull($type, $name);
            $fields = $type->getFields();
            $this->assertArrayHasKey('fakeNote', $fields, $name);
            $this->assertArrayHasKey('recurring', $fields, $name);
            $this->assertArrayHasKey('lines', $fields, $name);
        }
    }
}
