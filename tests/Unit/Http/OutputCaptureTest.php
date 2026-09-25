<?php

namespace FA\GraphQL\Tests\Unit\Http;

use FA\GraphQL\Http\OutputCapture;
use PHPUnit\Framework\TestCase;

class OutputCaptureTest extends TestCase
{
    private string $log = '';

    private string $previousLog = '';

    protected function setUp(): void
    {
        $this->log = (string) tempnam(sys_get_temp_dir(), 'capture-log');
        $this->previousLog = (string) ini_get('error_log');
        ini_set('error_log', $this->log);
    }

    protected function tearDown(): void
    {
        OutputCapture::end();
        ini_set('error_log', $this->previousLog);
        @unlink($this->log);
    }

    public function testEndReturnsWhatWasPrintedAndNothingReachesTheClient(): void
    {
        $this->expectOutputString('');

        OutputCapture::start();
        echo '<div class="err_msg">FrontAccounting says hello</div>';

        $this->assertSame('<div class="err_msg">FrontAccounting says hello</div>', OutputCapture::end());
    }

    public function testEndClosesEveryBufferAboveItsOwn(): void
    {
        $this->expectOutputString('');
        $level = ob_get_level();

        OutputCapture::start();
        echo 'one ';
        ob_start();
        echo 'two ';
        ob_start(static function (string $buffer): string {
            return '<html>' . $buffer . '</html>';
        });
        echo 'three';

        $this->assertSame('one two three', OutputCapture::end());
        $this->assertSame($level, ob_get_level());
    }

    public function testTheDiscardedOutputIsLoggedTruncatedWithItsLength(): void
    {
        $this->expectOutputString('');

        OutputCapture::start();
        echo str_repeat('a', 5000);
        OutputCapture::end();

        $log = (string) file_get_contents($this->log);
        $this->assertStringContainsString('5000 bytes of output', $log);
        $this->assertStringContainsString(str_repeat('a', OutputCapture::LOGGED_BYTES), $log);
        $this->assertStringNotContainsString(str_repeat('a', OutputCapture::LOGGED_BYTES + 1), $log);
    }

    public function testNothingIsLoggedWhenNothingWasPrinted(): void
    {
        OutputCapture::start();
        OutputCapture::end();

        $this->assertSame('', (string) file_get_contents($this->log));
    }

    public function testShutdownAfterEndPrintsNothing(): void
    {
        $this->expectOutputString('');

        OutputCapture::start();
        OutputCapture::end();
        OutputCapture::onShutdown();
    }

    public function testASecondEndReturnsNothing(): void
    {
        OutputCapture::start();
        echo 'x';
        OutputCapture::end();

        $this->assertSame('', OutputCapture::end());
    }
}
