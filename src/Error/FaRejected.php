<?php

namespace FA\GraphQL\Error;

/**
 * FrontAccounting refused the operation — through its own messages, or through one
 * of the page checks this module's services port. Carries the messages, which are
 * written for the person at the keyboard and are the useful part: a refusal given
 * none carries its own message, so extensions.messages is never empty (spec §5).
 */
class FaRejected extends ApiError
{
    /** @var string[] */
    private array $messages;

    private ?int $index;

    /**
     * @param string[] $messages all of them; none means [$message]
     * @param int|null $index the batch item refused, when in a batch
     */
    public function __construct(string $message, array $messages = [], ?int $index = null)
    {
        parent::__construct($message);
        $this->messages = $messages === [] ? [$message] : array_values($messages);
        $this->index = $index;
    }

    public function index(): ?int
    {
        return $this->index;
    }

    public function withIndex(int $index): FaRejected
    {
        return new FaRejected($this->getMessage(), $this->messages, $index);
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
