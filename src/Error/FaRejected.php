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

    private ?int $index;

    /**
     * @param string[] $messages
     * @param int|null $index the batch item FrontAccounting refused, when in a batch
     */
    public function __construct(string $message, array $messages = [], ?int $index = null)
    {
        parent::__construct($message);
        $this->messages = array_values($messages);
        $this->index = $index;
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
        $extensions = ['code' => $this->code(), 'messages' => $this->messages];
        if ($this->index !== null) {
            $extensions['index'] = $this->index;
        }

        return $extensions;
    }
}
