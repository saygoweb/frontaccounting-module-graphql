<?php

namespace FA\GraphQL\Error;

use GraphQL\Error\DebugFlag;
use GraphQL\Error\FormattedError;

/**
 * Three kinds of error reach a client: ours, with a code; GraphQL's own syntax and
 * validation errors, as they are; and everything else, masked and logged.
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
