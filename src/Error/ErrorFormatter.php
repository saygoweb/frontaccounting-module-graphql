<?php

namespace FA\GraphQL\Error;

use GraphQL\Error\DebugFlag;
use GraphQL\Error\FormattedError;
use GraphQL\Error\UserError;

/**
 * Four kinds of error reach a client: ours, with a code; a UserError (how
 * anorm-graphql's ModelType refuses a bad `query`), as BAD_INPUT; GraphQL's own
 * syntax and validation errors, as they are; and everything else, masked and logged.
 */
class ErrorFormatter
{
    private bool $debug;

    /** @var callable|null */
    private $logger;

    public function __construct(bool $debug, ?callable $logger = null)
    {
        $this->debug = $debug;
        $this->logger = $logger;
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(\Throwable $error): array
    {
        $flags = $this->debug ? DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::INCLUDE_TRACE : DebugFlag::NONE;
        $formatted = FormattedError::createFromException($error, $flags);
        $previous = $error->getPrevious();

        if ($previous instanceof ApiError) {
            $formatted['message'] = $previous->getMessage();
            $formatted['extensions'] = ($formatted['extensions'] ?? []) + (array) $previous->getExtensions();
            return $formatted;
        }
        if ($previous instanceof UserError) {
            $formatted['message'] = $previous->getMessage();
            $formatted['extensions'] = ['code' => 'BAD_INPUT'] + ($formatted['extensions'] ?? []);
            return $formatted;
        }
        if ($previous === null) {
            return $formatted;
        }

        if ($this->logger !== null) {
            ($this->logger)($previous);
        }
        $formatted['message'] = 'Internal server error';
        $formatted['extensions'] = ['code' => 'INTERNAL'] + ($formatted['extensions'] ?? []);
        if ($this->debug) {
            $formatted['extensions']['debugMessage'] = $previous->getMessage();
        }

        return $formatted;
    }
}
