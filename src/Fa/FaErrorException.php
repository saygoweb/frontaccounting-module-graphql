<?php

namespace FA\GraphQL\Fa;

/**
 * FrontAccounting raised E_USER_ERROR — in practice a failed query. Under the web
 * UI this becomes a red box in the page; here it has to stop the request.
 */
class FaErrorException extends \RuntimeException
{
}
