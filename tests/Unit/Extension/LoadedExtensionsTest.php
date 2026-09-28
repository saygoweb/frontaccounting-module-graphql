<?php

namespace FA\GraphQL\Tests\Unit\Extension;

use DI\ContainerBuilder;
use FA\GraphQL\Extension\ExtensionContext;
use FA\GraphQL\Extension\ExtensionLoader;
use FA\GraphQL\Extension\ExtensionRegistry;
use FA\GraphQL\Tests\Support\Extension\FakeExtension;
use GraphQL\Type\Definition\Type;
use PHPUnit\Framework\TestCase;

class LoadedExtensionsTest extends TestCase
{
    public function testAppendToAddsContributionsInListShapeAndDropsCoreClashes(): void
    {
        $log = [];
        $registry = new ExtensionRegistry();
        $registry->register(new FakeExtension('ext', ['types' => ['SalesOrderType' => [
            'extra' => ['type' => Type::string()],
            'id' => ['type' => Type::string()],
        ]]]));
        $loaded = (new ExtensionLoader(function (string $line) use (&$log): void {
            $log[] = $line;
        }))->load($registry, new ExtensionContext((new ContainerBuilder())->build()));

        $core = [['name' => 'id', 'type' => Type::id()], ['name' => 'reference', 'type' => Type::string()]];
        $merged = $loaded->appendTo('SalesOrderType', $core);

        $this->assertSame(['id', 'reference', 'extra'], array_column($merged, 'name'));
        $this->assertSame(Type::id(), $merged[0]['type']);
        $this->assertCount(1, $log);
        $this->assertStringContainsString('SalesOrderType.id', $log[0]);
    }

    public function testAppendToWithNothingContributedReturnsTheCoreUnchanged(): void
    {
        $core = [['name' => 'id', 'type' => Type::id()]];

        $none = \FA\GraphQL\Extension\LoadedExtensions::none();
        $this->assertSame($core, $none->appendTo('SalesOrderType', $core));
    }
}
