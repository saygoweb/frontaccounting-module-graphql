<?php

namespace FA\GraphQL\Tests\Unit\Fa\Service;

use FA\GraphQL\Fa\Service\ReportRunner;
use PHPUnit\Framework\TestCase;

class ReportRunnerTest extends TestCase
{
    public function testItCapturesStdoutStderrAndTheExitCode(): void
    {
        $run = (new ReportRunner())->run([PHP_BINARY, '-r', 'echo "out"; fwrite(STDERR, "err"); exit(3);'], 10);
        $this->assertSame('out', $run->stdout);
        $this->assertSame('err', $run->stderr);
        $this->assertSame(3, $run->exitCode);
        $this->assertFalse($run->timedOut);
    }

    public function testArgumentsAreNotInterpretedByAShell(): void
    {
        $run = (new ReportRunner())->run([PHP_BINARY, '-r', 'echo $argv[1];', '$(echo pwned); `id`'], 10);
        $this->assertSame('$(echo pwned); `id`', $run->stdout);
    }

    public function testATimedOutChildIsKilledAndReported(): void
    {
        $start = microtime(true);
        $run = (new ReportRunner())->run([PHP_BINARY, '-r', 'echo "started"; sleep(30);'], 1);
        $this->assertTrue($run->timedOut);
        $this->assertLessThan(10, microtime(true) - $start, 'the child must be stopped, not waited for');
        $this->assertSame('started', $run->stdout);
    }

    public function testLargeOutputOnBothStreamsDoesNotDeadlock(): void
    {
        $run = (new ReportRunner())->run(
            [PHP_BINARY, '-r', 'fwrite(STDERR, str_repeat("e", 200000)); echo str_repeat("o", 200000);'],
            10
        );
        $this->assertSame(200000, strlen($run->stdout));
        $this->assertSame(200000, strlen($run->stderr));
    }
}
