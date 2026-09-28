<?php

namespace FA\GraphQL\Tests\Unit\Extension;

use DI\ContainerBuilder;
use FA\GraphQL\ApiSchema;
use FA\GraphQL\Extension\CoreSchema;
use FA\GraphQL\Extension\ExtensionContext;
use FA\GraphQL\Extension\ExtensionLoader;
use FA\GraphQL\Extension\ExtensionRegistry;
use FA\GraphQL\Extension\LoadedExtensions;
use FA\GraphQL\Extension\SchemaAssembler;
use FA\GraphQL\Tests\Support\Extension\FakeExtension;
use GraphQL\GraphQL;
use GraphQL\Type\Definition\Type;
use PHPUnit\Framework\TestCase;

class SchemaAssemblerTest extends TestCase
{
    /** @var string[] */
    private array $log = [];

    private function core(): ApiSchema
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);

        return new ApiSchema($builder->build());
    }

    private function loaded(FakeExtension ...$extensions): LoadedExtensions
    {
        $registry = new ExtensionRegistry();
        foreach ($extensions as $extension) {
            $registry->register($extension);
        }

        return (new ExtensionLoader())->load($registry, new ExtensionContext((new ContainerBuilder())->build()));
    }

    public function testWithoutExtensionRootFieldsTheCoreSchemaIsReturnedAsItIs(): void
    {
        $core = $this->core();

        $this->assertSame($core, SchemaAssembler::build($core, LoadedExtensions::none()));
    }

    public function testExtensionRootFieldsAreServedBesideTheCore(): void
    {
        $loaded = $this->loaded(new FakeExtension('ext', [
            'query' => ['extHello' => [
                'type' => Type::nonNull(Type::string()),
                'resolve' => static function (): string {
                    return 'hello';
                },
            ]],
            'mutation' => ['extDo' => ['type' => Type::boolean(), 'resolve' => static function (): bool {
                return true;
            }]],
        ]));

        $schema = SchemaAssembler::build($this->core(), $loaded);
        $schema->assertValid();

        $this->assertTrue($schema->getQueryType()->hasField('extHello'));
        $this->assertTrue($schema->getQueryType()->hasField('apiVersion'));
        $this->assertTrue($schema->getMutationType()->hasField('extDo'));
        $this->assertTrue($schema->getMutationType()->hasField('login'));

        $result = GraphQL::executeQuery($schema, '{ extHello apiVersion }')->toArray();
        $this->assertSame('hello', $result['data']['extHello']);
        $this->assertSame(ApiSchema::VERSION, $result['data']['apiVersion']);
    }

    public function testAnExtensionClashingWithACoreRootFieldIsDroppedWholeBeforeAssembly(): void
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $container = $builder->build();
        $core = $container->get(ApiSchema::class);
        $registry = new ExtensionRegistry();
        $registry->register(new FakeExtension('ext', [
            'query' => [
                'apiVersion' => ['type' => Type::string()],
                'extOther' => ['type' => Type::string()],
            ],
        ]));
        $loaded = (new ExtensionLoader(function (string $line): void {
            $this->log[] = $line;
        }))->load($registry, new ExtensionContext($container), CoreSchema::fromContainer($container));

        $this->assertSame([], $loaded->names());
        $this->assertSame($core, SchemaAssembler::build($core, $loaded));
        $this->assertStringContainsString('graphql extension ext: Query.apiVersion', $this->log[0]);
    }

    /**
     * Defence in depth: loaded without the core's names (as here), a clash reaching
     * the assembler still leaves the core's field in place.
     */
    public function testARootFieldClashingWithTheCoreIsDroppedAndLogged(): void
    {
        $loaded = $this->loaded(new FakeExtension('ext', [
            'query' => [
                'apiVersion' => ['type' => Type::string(), 'resolve' => static function (): string {
                    return 'hijacked';
                }],
                'extOther' => ['type' => Type::string()],
            ],
        ]));

        $schema = SchemaAssembler::build($this->core(), $loaded, function (string $line): void {
            $this->log[] = $line;
        });

        $result = GraphQL::executeQuery($schema, '{ apiVersion }')->toArray();
        $this->assertSame(ApiSchema::VERSION, $result['data']['apiVersion']);
        $this->assertTrue($schema->getQueryType()->hasField('extOther'));
        $this->assertStringContainsString('graphql extension ext: Query.apiVersion', $this->log[0]);
    }
}
