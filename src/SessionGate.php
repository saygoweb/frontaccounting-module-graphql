<?php

namespace FA\GraphQL;

use FA\GraphQL\Auth\Claims;

/**
 * What the pipeline needs from FrontAccounting: load it, then become a verified
 * identity. FaSession is the real one; the middleware knows only this much, which
 * is what keeps the pipeline testable without FrontAccounting.
 */
interface SessionGate
{
    /**
     * Load FrontAccounting for this request. Called for every GraphQL request,
     * anonymous ones included.
     */
    public function boot(): void;

    /**
     * @throws \FA\GraphQL\Error\Unauthenticated the identity is no longer valid
     * @throws \FA\GraphQL\Error\Forbidden the identity may not use the API
     */
    public function enter(Claims $claims): void;
}
