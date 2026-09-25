<?php

namespace FA\GraphQL\Error;

/**
 * FrontAccounting refused the operation. Carries its own validation messages,
 * which are written for the person at the keyboard and are the useful part.
 */
class FaRejected extends ApiError
{
    /** @var string[] */
    private array $messages;

    /**
     * @param string[] $messages
     */
    public function __construct(string $message, array $messages = [])
    {
        parent::__construct($message);
        $this->messages = array_values($messages);
    }

    public function code(): string
    {
        return 'FA_REJECTED';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function getExtensions(): ?array
    {
        return ['code' => $this->code(), 'messages' => $this->messages];
    }
}
