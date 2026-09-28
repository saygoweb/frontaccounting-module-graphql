<?php

namespace FA\GraphQL\Extension;

/**
 * Takes part in sales order writes (Release 4 spec §2.4). SalesOrderService calls it
 * inside the order's FaTransaction, in registration order. An exception from any
 * method fails the mutation and rolls the order back; that is the point.
 */
interface SalesOrderParticipant
{
    /**
     * Before anything is written. Throw BadInput (field set; ServiceCall adds the
     * batch index) to refuse.
     *
     * @param array<string, mixed> $input the Create or Update input
     */
    public function validate(array $input): void;

    /**
     * True when the order's header must stay editable after delivery and the
     * delivered-quantity floor does not apply. The core ORs every participant.
     *
     * @param array<string, mixed> $input
     */
    public function isRelaxed(int $orderId, array $input): bool;

    /** @param array<string, mixed> $input */
    public function afterCreate(int $orderId, array $input): void;

    /** @param array<string, mixed> $input */
    public function afterUpdate(int $orderId, array $input): void;

    public function afterDelete(int $orderId): void;

    public function afterClose(int $orderId): void;
}
