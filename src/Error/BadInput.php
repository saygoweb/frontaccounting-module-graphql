<?php

namespace FA\GraphQL\Error;

/**
 * The request is malformed or violates a constraint. Names the input field when it
 * can, and — in a batch — the index of the item (Release 2 spec section 5).
 */
class BadInput extends ApiError
{
    private ?string $field;
    private ?int $index;

    public function __construct(string $message, ?string $field = null, ?int $index = null)
    {
        parent::__construct($message);
        $this->field = $field;
        $this->index = $index;
    }

    public function field(): ?string
    {
        return $this->field;
    }

    public function index(): ?int
    {
        return $this->index;
    }

    public function withIndex(int $index): BadInput
    {
        return new BadInput($this->getMessage(), $this->field, $index);
    }

    public function code(): string
    {
        return 'BAD_INPUT';
    }

    public function httpStatus(): int
    {
        return 400;
    }

    public function getExtensions(): ?array
    {
        $extensions = ['code' => $this->code()];
        if ($this->field !== null) {
            $extensions['field'] = $this->field;
        }
        if ($this->index !== null) {
            $extensions['index'] = $this->index;
        }

        return $extensions;
    }
}
