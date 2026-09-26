<?php

namespace FA\GraphQL\Type\Delivery;

use Anorm\DataMapper;
use Anorm\GraphQL\Builder\FieldBuilder;
use Anorm\GraphQL\Mapper;
use DI\Container;
use FA\GraphQL\Fa\DocumentLock;
use FA\GraphQL\Fa\Service\DeliveryService;
use FA\GraphQL\Fa\Service\ServiceCall;
use FA\GraphQL\Fa\Service\Voider;
use FA\GraphQL\Model\DeliveryLineModel;
use FA\GraphQL\Type\Delivery\Base\DeliveryTypeBase;
use FA\GraphQL\Type\DeliveryLine\DeliveryLineType;
use GraphQL\Type\Definition\Type;

/**
 * Yours to edit: anorm-graphql writes this file once and never again.
 *
 * A delivery: debtor_trans type 13 (Release 3 spec §2, §3). Written only through
 * FrontAccounting's Cart (DeliveryService); no update (generated --without-update);
 * delete voids.
 */
class DeliveryType extends DeliveryTypeBase
{
    private DeliveryLineType $lineType;

    public function __construct(DeliveryLineType $lineType)
    {
        $this->lineType = $lineType;
        parent::__construct();
    }

    /**
     * @return array<string, string>
     */
    protected function areas(): array
    {
        return [
            self::VERB_LIST => 'SA_SALESTRANSVIEW',
            self::VERB_CREATE => 'SA_SALESDELIVERY',
            self::VERB_DELETE => 'SA_VOIDTRANSACTION',
        ];
    }

    protected function scope(): array
    {
        return ['transType' => DeliveryService::TRANS_TYPE];
    }

    protected function fields(): array
    {
        return array_merge(parent::fields(), [
            FieldBuilder::create('lines', Type::nonNull(Type::listOf(Type::nonNull($this->lineType))))
                ->setDescription('The delivery\'s lines, in entry order.')
                ->setResolver(function (array $row, $args, $context): array {
                    return $row['lines'] ?? self::linesOf((int) $row['id'], $context);
                })
                ->build(),
            FieldBuilder::create('voided', Type::nonNull(Type::boolean()))
                ->setDescription('Whether the delivery has been voided (deliveryDelete).')
                ->setResolver(function (array $row, $args, $context): bool {
                    return $row['voided'] ?? $context->get(Voider::class)
                        ->isVoided(DeliveryService::TRANS_TYPE, (int) $row['id']);
                })
                ->build(),
        ]);
    }

    /**
     * Each delivery through DeliveryService, the batch in one FrontAccounting
     * transaction under the document lock (spec §2.1), read back once it has
     * committed — authorised by the create area.
     */
    public function resolveCreate($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_CREATE, null, $context);
        $service = $context->get(DeliveryService::class);
        $ids = DocumentLock::run(function () use ($args, $service): array {
            return ServiceCall::each($args['input'], function (array $input) use ($service): int {
                return $service->create($input);
            });
        });

        return $this->rowsById($context, $ids, self::VERB_CREATE);
    }

    /**
     * Voids each delivery (spec §3) and returns it as it was, lines included.
     */
    public function resolveDelete($root, $args, Container $context): array
    {
        $this->authorize(self::VERB_DELETE, null, $context);
        $ids = self::intIds($args['id']);
        $before = [];
        foreach ($this->rowsById($context, $ids, self::VERB_DELETE) as $index => $row) {
            $row['lines'] = self::linesOf($ids[$index], $context);
            $row['voided'] = false;
            $before[] = $row;
        }
        $service = $context->get(DeliveryService::class);
        DocumentLock::run(function () use ($ids, $service): void {
            ServiceCall::each($ids, function (int $id) use ($service): void {
                $service->delete($id);
            });
        });

        return $before;
    }

    /**
     * A delivery's lines, read directly: the delivery's own read already passed its
     * area, and a role that may create deliveries gets its lines back.
     *
     * @param mixed $context the container
     * @return array<int, array<string, mixed>>
     */
    public static function linesOf(int $deliveryId, $context): array
    {
        $rows = [];
        $lines = DataMapper::find(DeliveryLineModel::class, $context->get(\PDO::class))
            ->where(
                'debtor_trans_no = :id AND debtor_trans_type = :type',
                [':id' => $deliveryId, ':type' => DeliveryService::TRANS_TYPE]
            )
            ->orderBy('id')
            ->some();
        foreach ($lines as $line) {
            $rows[] = Mapper::toArray($line);
        }

        return $rows;
    }
}
