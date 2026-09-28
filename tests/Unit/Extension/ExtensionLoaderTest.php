<?php

namespace FA\GraphQL\Tests\Unit\Extension;

use DI\Container;
use DI\ContainerBuilder;
use FA\GraphQL\Extension\CoreSchema;
use FA\GraphQL\Extension\ExtensionContext;
use FA\GraphQL\Extension\ExtensionLoader;
use FA\GraphQL\Extension\ExtensionRegistry;
use FA\GraphQL\Extension\LoadedExtensions;
use FA\GraphQL\Tests\Support\Extension\FakeExtension;
use FA\GraphQL\Tests\Support\Extension\RecordingParticipant;
use FA\GraphQL\Type\Invoice\InvoiceEmailResultType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use PHPUnit\Framework\TestCase;

class ExtensionLoaderTest extends TestCase
{
    /** @var string[] */
    private array $log = [];

    private function load(FakeExtension ...$extensions): LoadedExtensions
    {
        $registry = new ExtensionRegistry();
        foreach ($extensions as $extension) {
            $registry->register($extension);
        }
        $loader = new ExtensionLoader(function (string $line): void {
            $this->log[] = $line;
        });

        return $loader->load($registry, new ExtensionContext((new ContainerBuilder())->build()));
    }

    private static function field(): array
    {
        return ['type' => Type::string(), 'resolve' => static function (): string {
            return 'x';
        }];
    }

    public function testContributionsOfAGoodExtensionAreLoaded(): void
    {
        $participant = new RecordingParticipant();
        $loaded = $this->load(new FakeExtension('good', [
            'query' => ['goodList' => self::field()],
            'mutation' => ['goodDo' => self::field()],
            'types' => ['SalesOrderType' => ['goodField' => self::field()]],
            'inputs' => ['SalesOrderCreateInput' => ['goodInput' => ['type' => Type::string()]]],
            'participants' => [$participant],
        ]));

        $this->assertSame(['good'], $loaded->names());
        $this->assertSame(['goodList'], array_keys($loaded->queryFields()));
        $this->assertSame(['goodDo'], array_keys($loaded->mutationFields()));
        $this->assertSame(['goodField'], array_keys($loaded->typeFields('SalesOrderType')));
        $this->assertSame(['goodInput'], array_keys($loaded->inputFields('SalesOrderCreateInput')));
        $this->assertSame([], $loaded->inputFields('SalesOrderUpdateInput'));
        $this->assertSame([$participant], $loaded->salesOrderParticipants());
        $this->assertSame('good', $loaded->ownerOf('query', 'goodList'));
        $this->assertSame([], $this->log);
    }

    public function testAnotherMajorContractVersionIsDropped(): void
    {
        $loaded = $this->load(new FakeExtension('future', ['version' => '2.0', 'query' => ['f' => self::field()]]));

        $this->assertSame([], $loaded->names());
        $this->assertStringContainsString('graphql extension future:', $this->log[0]);
        $this->assertStringContainsString('2.0', $this->log[0]);
    }

    public function testAMinorContractVersionIsAccepted(): void
    {
        $loaded = $this->load(new FakeExtension('minor', ['version' => '1.3']));

        $this->assertSame(['minor'], $loaded->names());
    }

    /**
     * @dataProvider throwingMethods
     */
    public function testAnExtensionThrowingWhileContributingIsDroppedAndTheRestLoad(string $method): void
    {
        $loaded = $this->load(
            new FakeExtension('broken', ['throwIn' => $method, 'query' => ['brokenList' => self::field()]]),
            new FakeExtension('fine', ['query' => ['fineList' => self::field()]])
        );

        $this->assertSame(['fine'], $loaded->names());
        $this->assertSame(['fineList'], array_keys($loaded->queryFields()));
        $this->assertCount(1, $this->log);
        $this->assertStringContainsString("boom in $method", $this->log[0]);
    }

    public function throwingMethods(): array
    {
        return array_map(static function (string $m): array {
            return [$m];
        }, ['name', 'contractVersion', 'queryFields', 'mutationFields', 'typeFields', 'inputFields', 'participants']);
    }

    public function testATargetTheCoreDoesNotMarkExtensibleDropsTheExtension(): void
    {
        $loaded = $this->load(new FakeExtension('greedy', [
            'types' => ['CustomerType' => ['extra' => self::field()]],
        ]));

        $this->assertSame([], $loaded->names());
        $this->assertStringContainsString('CustomerType', $this->log[0]);
    }

    public function testAnInputContributedAsATypeFieldIsRefused(): void
    {
        $loaded = $this->load(new FakeExtension('mixed', [
            'types' => ['SalesOrderCreateInput' => ['extra' => self::field()]],
        ]));

        $this->assertSame([], $loaded->names());
    }

    public function testANonNullInputFieldDropsTheExtension(): void
    {
        $loaded = $this->load(new FakeExtension('strict', [
            'inputs' => ['SalesOrderUpdateInput' => ['must' => ['type' => Type::nonNull(Type::string())]]],
        ]));

        $this->assertSame([], $loaded->names());
        $this->assertStringContainsString('nullable', $this->log[0]);
    }

    public function testTheLaterOfTwoClashingExtensionsIsDroppedWhole(): void
    {
        $loaded = $this->load(
            new FakeExtension('first', ['query' => ['sharedList' => self::field()]]),
            new FakeExtension('second', [
                'query' => ['sharedList' => self::field()],
                'mutation' => ['secondOnly' => self::field()],
            ])
        );

        $this->assertSame(['first'], $loaded->names());
        $this->assertSame([], $loaded->mutationFields());
        $this->assertStringContainsString('graphql extension second:', $this->log[0]);
    }

    public function testTypeFieldClashesBetweenExtensionsDropTheLaterOne(): void
    {
        $loaded = $this->load(
            new FakeExtension('first', ['types' => ['SalesOrderType' => ['same' => self::field()]]]),
            new FakeExtension('second', ['types' => ['SalesOrderType' => ['same' => self::field()]]])
        );

        $this->assertSame(['first'], $loaded->names());
    }

    public function testADuplicateExtensionNameIsDropped(): void
    {
        $loaded = $this->load(new FakeExtension('twin'), new FakeExtension('twin'));

        $this->assertSame(['twin'], $loaded->names());
        $this->assertCount(1, $this->log);
    }

    public function testAnInvalidFieldNameDropsTheExtension(): void
    {
        $loaded = $this->load(new FakeExtension('odd', ['query' => ['bad-name' => self::field()]]));

        $this->assertSame([], $loaded->names());
    }

    public function testAConfigWhoseNameDiffersFromItsKeyDropsTheExtension(): void
    {
        $loaded = $this->load(new FakeExtension('liar', [
            'query' => ['one' => ['name' => 'other'] + self::field()],
        ]));

        $this->assertSame([], $loaded->names());
    }

    public function testAParticipantOfNoKnownKindDropsTheExtension(): void
    {
        $loaded = $this->load(new FakeExtension('stranger', ['participants' => [new \stdClass()]]));

        $this->assertSame([], $loaded->names());
        $this->assertStringContainsString('participant', $this->log[0]);
    }

    /**
     * Loaded as Extensions loads them: told the core's root fields, the extensible
     * types' own fields and the core's types, from the same container.
     */
    private function loadAgainstCore(Container $container, FakeExtension ...$extensions): LoadedExtensions
    {
        $registry = new ExtensionRegistry();
        foreach ($extensions as $extension) {
            $registry->register($extension);
        }
        $loader = new ExtensionLoader(function (string $line): void {
            $this->log[] = $line;
        });

        return $loader->load($registry, new ExtensionContext($container), CoreSchema::fromContainer($container));
    }

    private static function coreContainer(): Container
    {
        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);

        return $builder->build();
    }

    /**
     * Binding ruling 1: any clash with a core field drops the whole extension, its
     * participants included — otherwise its participant would write beside the
     * core's own code for the field the core kept.
     *
     * @dataProvider coreClashes
     */
    public function testAFieldTheCoreHasDropsTheWholeExtension(string $key, string $target, string $field): void
    {
        $participant = new RecordingParticipant();
        $contribution = $key === 'query' || $key === 'mutation'
            ? [$field => self::field()]
            : [$target => [$field => $key === 'inputs' ? ['type' => Type::string()] : self::field()]];
        $loaded = $this->loadAgainstCore(self::coreContainer(), new FakeExtension('clashing', [
            $key => $contribution,
            'query' => ($key === 'query' ? $contribution : []) + ['clashingOwn' => self::field()],
            'participants' => [$participant],
        ]));

        $this->assertSame([], $loaded->names());
        $this->assertSame([], $loaded->queryFields());
        $this->assertSame([], $loaded->salesOrderParticipants());
        $this->assertCount(1, $this->log);
        $this->assertStringContainsString('graphql extension clashing:', $this->log[0]);
        $this->assertStringContainsString("$target.$field", $this->log[0]);
        $this->assertStringContainsString('core', $this->log[0]);
    }

    public function coreClashes(): array
    {
        return [
            'a type field' => ['types', 'SalesOrderType', 'recurring'],
            'a generated type field' => ['types', 'SalesOrderType', 'reference'],
            'a create input field' => ['inputs', 'SalesOrderCreateInput', 'recurring'],
            'an update input field' => ['inputs', 'SalesOrderUpdateInput', 'lines'],
            'a root query field' => ['query', 'Query', 'apiVersion'],
            'a root mutation field' => ['mutation', 'Mutation', 'salesOrderCreate'],
        ];
    }

    public function testAFieldTheCoreDropsFromAnInputIsFreeToAdd(): void
    {
        // SalesOrderCreateInput leaves out the generated 'total': it is not a core field.
        $loaded = $this->loadAgainstCore(self::coreContainer(), new FakeExtension('fills', [
            'inputs' => ['SalesOrderCreateInput' => ['total' => ['type' => Type::float()]]],
        ]));

        $this->assertSame(['fills'], $loaded->names());
    }

    /**
     * Binding ruling 2: the core's own type object is not a clash; another type of
     * the same name is.
     */
    public function testReusingTheCoresTypeObjectIsNotAClash(): void
    {
        $container = self::coreContainer();
        $loaded = $this->loadAgainstCore($container, new FakeExtension('mailer', [
            'mutation' => ['mailerSend' => [
                'type' => Type::listOf($container->get(InvoiceEmailResultType::class)),
            ]],
        ]));

        $this->assertSame(['mailer'], $loaded->names());
        $this->assertSame([], $this->log);
    }

    public function testAnotherTypeWithACoreTypesNameDropsTheWholeExtension(): void
    {
        $impostor = new ObjectType(['name' => 'InvoiceEmailResult', 'fields' => ['id' => Type::id()]]);
        $participant = new RecordingParticipant();
        $loaded = $this->loadAgainstCore(self::coreContainer(), new FakeExtension('impostor', [
            'types' => ['SalesOrderType' => ['impostorResult' => ['type' => $impostor]]],
            'participants' => [$participant],
        ]));

        $this->assertSame([], $loaded->names());
        $this->assertSame([], $loaded->salesOrderParticipants());
        $this->assertStringContainsString('graphql extension impostor:', $this->log[0]);
        $this->assertStringContainsString('InvoiceEmailResult', $this->log[0]);
    }

    public function testATypeNameClashFoundDeepInAnExtensionsTypesDropsIt(): void
    {
        $impostor = new ObjectType(['name' => 'SalesOrderType', 'fields' => ['id' => Type::id()]]);
        $wrapper = new ObjectType(['name' => 'DeepWrapper', 'fields' => static function () use ($impostor): array {
            return ['order' => ['type' => $impostor, 'args' => ['x' => ['type' => Type::int()]]]];
        }]);
        $loaded = $this->loadAgainstCore(self::coreContainer(), new FakeExtension('deep', [
            'query' => ['deepList' => ['type' => Type::nonNull(Type::listOf($wrapper))]],
        ]));

        $this->assertSame([], $loaded->names());
        $this->assertStringContainsString('SalesOrderType', $this->log[0]);
    }

    public function testTwoExtensionsMayShareATypeObjectButNotATypeName(): void
    {
        $shared = new ObjectType(['name' => 'SharedThing', 'fields' => ['id' => Type::id()]]);
        $other = new ObjectType(['name' => 'SharedThing', 'fields' => ['id' => Type::id()]]);
        $loaded = $this->load(
            new FakeExtension('first', ['query' => ['firstThing' => ['type' => $shared]]]),
            new FakeExtension('second', ['query' => ['secondThing' => ['type' => $shared]]]),
            new FakeExtension('third', ['query' => ['thirdThing' => ['type' => $other]]])
        );

        $this->assertSame(['first', 'second'], $loaded->names());
        $this->assertCount(1, $this->log);
        $this->assertStringContainsString('graphql extension third:', $this->log[0]);
        $this->assertStringContainsString('SharedThing', $this->log[0]);
    }

    public function testAFieldConfigWebonyxCannotReadDropsTheExtension(): void
    {
        $loaded = $this->load(new FakeExtension('garbled', [
            'query' => ['garbledList' => ['type' => 'not a type']],
        ]));

        $this->assertSame([], $loaded->names());
    }

    /**
     * @dataProvider wrongKinds
     */
    public function testAFieldOfTheWrongKindOfTypeDropsTheExtension(string $key, string $target): void
    {
        $input = new \GraphQL\Type\Definition\InputObjectType(['name' => 'OddInput', 'fields' => ['a' => Type::int()]]);
        $output = new ObjectType(['name' => 'OddOutput', 'fields' => ['a' => Type::int()]]);
        $options = $key === 'inputs'
            ? ['inputs' => [$target => ['odd' => ['type' => $output]]]]
            : ($key === 'types'
                ? ['types' => [$target => ['odd' => ['type' => $input]]]]
                : [$key => ['odd' => ['type' => Type::listOf($input)]]]);
        $loaded = $this->load(new FakeExtension('kinds', $options));

        $this->assertSame([], $loaded->names());
        $this->assertCount(1, $this->log);
        $this->assertStringContainsString('graphql extension kinds:', $this->log[0]);
        $this->assertStringContainsString('.odd', $this->log[0]);
        $this->assertStringContainsString($key === 'inputs' ? 'input type' : 'output type', $this->log[0]);
    }

    public function wrongKinds(): array
    {
        return [
            'an output type on an input' => ['inputs', 'SalesOrderCreateInput'],
            'an input type on a type' => ['types', 'SalesOrderType'],
            'an input type on a root query field' => ['query', 'Query'],
            'an input type on a root mutation field' => ['mutation', 'Mutation'],
        ];
    }

    public function testANonNullFieldOnACoreTypeDropsTheExtension(): void
    {
        $loaded = $this->load(new FakeExtension('bold', [
            'types' => ['SalesOrderType' => ['bold' => ['type' => Type::nonNull(Type::string())]]],
        ]));

        $this->assertSame([], $loaded->names());
        $this->assertStringContainsString('SalesOrderType.bold', $this->log[0]);
        $this->assertStringContainsString('nullable', $this->log[0]);
    }

    public function testANonNullRootFieldIsAllowed(): void
    {
        $loaded = $this->load(new FakeExtension('rooted', [
            'query' => ['rootedHello' => ['type' => Type::nonNull(Type::string())]],
        ]));

        $this->assertSame(['rooted'], $loaded->names());
    }

    public function testATargetWhoseFieldsAreNotAnArrayDropsTheExtension(): void
    {
        $loaded = $this->load(new FakeExtension('flat', ['types' => ['SalesOrderType' => 'x']]));

        $this->assertSame([], $loaded->names());
        $this->assertStringContainsString("SalesOrderType's fields must be an array", $this->log[0]);
    }

    public function testNoneLoadsNothing(): void
    {
        $none = LoadedExtensions::none();

        $this->assertSame([], $none->names());
        $this->assertSame([], $none->queryFields());
        $this->assertSame([], $none->salesOrderParticipants());
    }
}
