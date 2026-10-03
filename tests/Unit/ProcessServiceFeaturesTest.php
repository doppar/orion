<?php

namespace Doppar\Orion\Tests\Unit;

use Doppar\Orion\Process\ProcessResult;
use Doppar\Orion\Process\ProcessService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

class ProcessServiceFeaturesTest extends TestCase
{
    // ----- reading the command ----------------------------------------------------------

    public function testQuotedArgumentsAreReadLikeAShellDoes(): void
    {
        $this->assertSame("hello world\n", ProcessService::create('echo "hello world"')->execute()->getOutput());
        $this->assertSame('a b|c', ProcessService::create("printf '%s|%s' 'a b' c")->execute()->getOutput());
        $this->assertSame('[a][b]', ProcessService::create('printf "[%s]" a   b')->execute()->getOutput());
    }

    public function testNothingIsRunThroughAShell(): void
    {
        $this->assertSame("\$HOME\n", ProcessService::create('echo $HOME')->execute()->getOutput());
        $this->assertSame("hi | tr a-z A-Z\n", ProcessService::create('echo hi | tr a-z A-Z')->execute()->getOutput());
        $this->assertSame("a; echo INJECTED\n", ProcessService::create('echo a; echo INJECTED')->execute()->getOutput());
    }

    public function testAnArrayCommandIsUsedAsItIs(): void
    {
        $result = ProcessService::create([PHP_BINARY, '-r', 'echo "a;b|c";'])->execute();

        $this->assertSame('a;b|c', $result->getOutput());
    }

    public function testAnUnclosedQuoteIsReportedBeforeAnythingRuns(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ProcessService::create('echo "oops')->execute();
    }

    public function testWithoutACommandThereIsAClearError(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('There is no command to run.');

        ProcessService::pingSilently()->execute();
    }

    // ----- not started ------------------------------------------------------------------

    public function testUsingAServiceThatWasNeverStartedExplainsWhatToDo(): void
    {
        $service = ProcessService::create('echo x');

        foreach (
            [
                fn() => $service->waitForCompletion(),
                fn() => $service->isRunning(),
                fn() => $service->until(fn() => true),
                fn() => $service->getLatestOutput(),
                fn() => $service->getLatestError(),
                fn() => $service->verifyTimeout(),
                fn() => $service->getPid(),
                fn() => $service->result(),
                fn() => $service->stop(),
            ] as $call
        ) {
            try {
                $call();
                $this->fail('Expected a LogicException');
            } catch (\LogicException $e) {
                $this->assertSame('The process has not been started. Call execute() or asAsync() first.', $e->getMessage());
            }
        }
    }

    // ----- timeouts ---------------------------------------------------------------------

    public function testVerifyTimeoutThrowsTheRealTimeoutException(): void
    {
        $service = ProcessService::create('sleep 3')->withTimeout(1)->asAsync();
        usleep(1300000);

        try {
            $service->verifyTimeout();
            $this->fail('Expected the process to have timed out');
        } catch (\Exception $e) {
            $this->assertInstanceOf(ProcessTimedOutException::class, $e);
            $this->assertStringContainsString("'sleep' '3'", $e->getMessage());
        }

        $this->assertFalse($service->isRunning(), 'checking a process that is over its timeout stops it');
    }

    public function testATimeoutCanBeFractional(): void
    {
        $this->expectException(ProcessTimedOutException::class);

        ProcessService::create('sleep 3')->withTimeout(0.3)->execute();
    }

    public function testATimeoutCanBeRemoved(): void
    {
        $this->assertSame("done\n", ProcessService::create('echo done')->withoutTimeout()->execute()->getOutput());
        $this->assertTrue(ProcessService::create('echo done')->withTimeout(null)->execute()->wasSuccessful());
        $this->assertTrue(ProcessService::create('echo done')->withTimeout(0)->execute()->wasSuccessful());
    }

    public function testAnIdleTimeoutStopsAProcessThatStaysSilent(): void
    {
        $this->expectException(ProcessTimedOutException::class);

        ProcessService::create('sleep 3')->withIdleTimeout(0.3)->execute();
    }

    // ----- async ------------------------------------------------------------------------

    public function testTheOutputHandlerReceivesOutputFromAnAsyncProcess(): void
    {
        $received = [];

        $service = ProcessService::create([PHP_BINARY, '-r', 'echo "to-out"; fwrite(STDERR, "to-err");'])
            ->withOutputHandler(function (string $type, string $buffer) use (&$received) {
                $received[$type] = ($received[$type] ?? '') . $buffer;
            })
            ->asAsync();

        $service->waitForCompletion();

        $this->assertSame('to-out', $received['out']);
        $this->assertSame('to-err', $received['err']);
    }

    public function testWaitForCompletionAcceptsAHandlerOfItsOwn(): void
    {
        $seen = '';

        ProcessService::create('echo streamed')->asAsync()->waitForCompletion(function (string $type, string $buffer) use (&$seen) {
            $seen .= $buffer;
        });

        $this->assertSame("streamed\n", $seen);
    }

    public function testAnAsyncProcessCanBeStopped(): void
    {
        $service = ProcessService::create('sleep 5')->asAsync();

        $this->assertIsInt($service->getPid());
        $this->assertTrue($service->isRunning());

        $service->stop(1);

        $this->assertFalse($service->isRunning());
        $this->assertNull($service->getPid());
    }

    public function testAnAsyncProcessCanBeSignalled(): void
    {
        $service = ProcessService::create('sleep 5')->asAsync();

        $this->assertSame($service, $service->signal(15));

        $result = $service->waitForCompletion();

        $this->assertTrue($result->failed());
        $this->assertSame(15, $result->getSignal());
    }

    public function testTheResultOfAProcessThatIsStillRunningHasNoExitCodeYet(): void
    {
        $service = ProcessService::create('sleep 2')->asAsync();

        $running = $service->result();

        $this->assertSame(-1, $running->getExitCode(), 'a missing exit code must not be a TypeError');
        $this->assertNull($running->getDuration());

        $service->stop(0);
    }

    // ----- the result -------------------------------------------------------------------

    public function testTheResultKnowsHowLongItTookAndWhatRan(): void
    {
        $result = ProcessService::create('sleep 1')->execute();

        $this->assertGreaterThanOrEqual(0.9, $result->getDuration());
        $this->assertLessThan(3, $result->getDuration());
        $this->assertSame("'sleep' '1'", $result->getCommandLine());
        $this->assertSame([0], $result->getExitCodes());
        $this->assertFalse($result->timedOut());
        $this->assertNull($result->getSignal());
    }

    public function testThrowTurnsAFailureIntoAnException(): void
    {
        $result = ProcessService::create([PHP_BINARY, '-r', 'fwrite(STDERR, "it broke"); exit(3);'])->execute();

        $this->assertTrue($result->failed());
        $this->assertSame(3, $result->getExitCode());

        try {
            $result->throw();
            $this->fail('Expected a ProcessFailedException');
        } catch (ProcessFailedException $e) {
            $this->assertStringContainsString('it broke', $e->getMessage());
            $this->assertStringContainsString('Exit Code: 3', $e->getMessage());
        }
    }

    public function testThrowDoesNothingForASuccessfulProcess(): void
    {
        $result = ProcessService::create('echo ok')->execute();

        $this->assertFalse($result->failed());
        $this->assertSame($result, $result->throw());
        $this->assertSame($result, $result->throwIf(true));
    }

    public function testThrowIfOnlyThrowsWhenTheConditionHolds(): void
    {
        $result = ProcessService::create([PHP_BINARY, '-r', 'exit(2);'])->execute();

        $this->assertSame($result, $result->throwIf(false));
        $this->assertSame($result, $result->throwIf(fn(ProcessResult $r) => $r->getExitCode() === 9));

        $this->expectException(ProcessFailedException::class);
        $result->throwIf(fn(ProcessResult $r) => $r->getExitCode() === 2);
    }

    public function testLines(): void
    {
        $this->assertSame(['one', 'two', '', 'four'], ProcessService::create([PHP_BINARY, '-r', 'echo "one\r\ntwo\n\nfour\n";'])->execute()->lines());
        $this->assertSame([], ProcessService::create([PHP_BINARY, '-r', 'echo "";'])->execute()->lines());
    }

    public function testJson(): void
    {
        $result = ProcessService::create([PHP_BINARY, '-r', 'echo json_encode(["a" => ["b" => 5], "list" => [1, 2]]);'])->execute();

        $this->assertSame(['a' => ['b' => 5], 'list' => [1, 2]], $result->json());
        $this->assertSame(5, $result->json('a.b'));
        $this->assertSame('none', $result->json('a.missing', 'none'));
        $this->assertSame('none', $result->json('list.deeper.still', 'none'));
    }

    public function testJsonThrowsForOutputThatIsNotJson(): void
    {
        $this->expectException(\JsonException::class);

        ProcessService::create('echo not-json')->execute()->json();
    }

    public function testInDirectoryAcceptsNullToMeanTheCurrentOne(): void
    {
        $result = ProcessService::create('pwd')->inDirectory(null)->execute();

        $this->assertSame(getcwd(), trim($result->getOutput()));
    }
}
