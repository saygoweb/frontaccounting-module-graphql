<?php

namespace FA\GraphQL;

class Response
{
    /** @var int */
    public $status;

    /** @var array<string, mixed> */
    public $body;

    /**
     * @param array<string, mixed> $body
     */
    public function __construct(int $status, array $body)
    {
        $this->status = $status;
        $this->body = $body;
    }

    /**
     * An error in the shape GraphQL clients already parse.
     */
    public static function error(int $status, string $message): self
    {
        return new self($status, ['errors' => [['message' => $message]]]);
    }
}
