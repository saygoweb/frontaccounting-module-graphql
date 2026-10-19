<?php

namespace FA\GraphQL\Tests\Unit\Api;

use FA\GraphQL\Api\DocumentRunner;
use FA\GraphQL\Auth\Claims;
use FA\GraphQL\Config;
use FA\GraphQL\Error\ErrorFormatter;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use PHPUnit\Framework\TestCase;

final class DocumentRunnerTest extends TestCase
{
    /** @var string[] */
    private array $ran = [];

    private function schema(): Schema
    {
        return new Schema([
            'query' => new ObjectType(['name' => 'Query', 'fields' => [
                'a' => ['type' => Type::string(), 'resolve' => function () {
                    $this->ran[] = 'a';
                    return 'A';
                }],
            ]]),
            'mutation' => new ObjectType(['name' => 'Mutation', 'fields' => [
                'b' => ['type' => Type::string(), 'resolve' => function () {
                    $this->ran[] = 'b';
                    return 'B';
                }],
            ]]),
        ]);
    }

    private function runner(): DocumentRunner
    {
        return new DocumentRunner(
            Config::fromArray(['secret' => str_repeat('s', 32)]),
            new ErrorFormatter(false, static function (): void {
            })
        );
    }

    private function claims(string $scope): Claims
    {
        return new Claims(0, 'admin', 'j', new \DateTimeImmutable('+1 hour'), false, $scope);
    }

    public function testQueryRunsForReadToken(): void
    {
        $out = $this->runner()->run($this->schema(), null, '{ a }', null, null, $this->claims('read'));
        $this->assertSame(['data' => ['a' => 'A']], $out);
    }

    public function testMutationRefusedForReadTokenWithoutRunning(): void
    {
        $out = $this->runner()->run($this->schema(), null, 'mutation { b }', null, null, $this->claims('read'));
        $this->assertSame('FORBIDDEN', $out['errors'][0]['extensions']['code']);
        $this->assertSame('This token is read-only.', $out['errors'][0]['message']);
        $this->assertArrayNotHasKey('data', $out);
        $this->assertSame([], $this->ran);
    }

    public function testNamedQueryInDocumentWithMutationRunsForReadToken(): void
    {
        $doc = 'query Q { a } mutation M { b }';
        $out = $this->runner()->run($this->schema(), null, $doc, null, 'Q', $this->claims('read'));
        $this->assertSame(['data' => ['a' => 'A']], $out);
    }

    public function testNamedMutationRefusedForReadToken(): void
    {
        $doc = 'query Q { a } mutation M { b }';
        $out = $this->runner()->run($this->schema(), null, $doc, null, 'M', $this->claims('read'));
        $this->assertSame('FORBIDDEN', $out['errors'][0]['extensions']['code']);
    }

    public function testAQueryAndAMutationSharingTheNameAreRefusedForReadToken(): void
    {
        // webonyx would run the last operation with the name: fail closed on any.
        $doc = 'query A { a } mutation A { b }';
        $out = $this->runner()->run($this->schema(), null, $doc, null, 'A', $this->claims('read'));
        $this->assertSame(self::readOnly(), $out);
        $this->assertSame([], $this->ran);
    }

    public function testSeveralOperationsWithoutANameIncludingAMutationAreRefusedForReadToken(): void
    {
        $doc = 'query { a } mutation M { b }';
        $out = $this->runner()->run($this->schema(), null, $doc, null, null, $this->claims('read'));
        $this->assertSame(self::readOnly(), $out);
        $this->assertSame([], $this->ran);
    }

    public function testSeveralQueriesWithoutANameAreLeftToExecuteQuery(): void
    {
        $doc = 'query A { a } query B { a }';
        $out = $this->runner()->run($this->schema(), null, $doc, null, null, $this->claims('read'));
        $this->assertNotSame(self::readOnly(), $out);
        $this->assertArrayHasKey('errors', $out);
    }

    /**
     * @return array<string, mixed>
     */
    private static function readOnly(): array
    {
        return ['errors' => [['message' => DocumentRunner::READ_ONLY, 'extensions' => ['code' => 'FORBIDDEN']]]];
    }

    public function testMutationRunsForWriteTokenAndAnonymous(): void
    {
        $this->assertSame(
            ['data' => ['b' => 'B']],
            $this->runner()->run($this->schema(), null, 'mutation { b }', null, null, $this->claims('write'))
        );
        $this->assertSame(
            ['data' => ['b' => 'B']],
            $this->runner()->run($this->schema(), null, 'mutation { b }', null, null, null)
        );
    }

    public function testSyntaxErrorIsReportedNotThrown(): void
    {
        $out = $this->runner()->run($this->schema(), null, 'mutation {', null, null, $this->claims('read'));
        $this->assertArrayHasKey('errors', $out);
    }
}
