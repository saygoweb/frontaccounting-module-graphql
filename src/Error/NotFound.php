<?php

namespace FA\GraphQL\Error;

/**
 * The requested resource does not exist. In a batch, names the index of the item
 * that named it (Release 2 spec section 3.1).
 */
class NotFound extends ApiError
{
    private ?int $index;

    public function __construct(string $message, ?int $index = null)
    {
        parent::__construct($message);
        $this->index = $index;
    }

    public function index(): ?int
    {
        return $this->index;
    }

    public function withIndex(int $index): NotFound
    {
        return new NotFound($this->getMessage(), $index);
    }

    public function code(): string
    {
        return 'NOT_FOUND';
    }

    public function httpStatus(): int
    {
        return 404;
    }

    public function getExtensions(): ?array
    {
        $extensions = ['code' => $this->code()];
        if ($this->index !== null) {
            $extensions['index'] = $this->index;
        }

        return $extensions;
    }
}
