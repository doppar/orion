<?php

namespace Doppar\Orion\Tests\Unit;

use Doppar\Orion\Process\ProcessPool;
use Doppar\Orion\Process\ProcessResult;
use PHPUnit\Framework\TestCase;

class ProcessPoolTest extends TestCase
{
    public function testItWorksWithoutAWorkingDirectory(): void
    {
        // inDirectory(null) used to be a TypeError, so a pool without a directory could not run.
        $results = ProcessPool::create()->add('echo a')->add('echo b')->waitForAll();

        $this->assertSame(['a', 'b'], array_map(fn(ProcessResult $r) => trim($r->getOutput()), $results));
    }

    public function testResultsAreKeyedByPositionWhateverOrderTheyFinish(): void
    {
        $results = ProcessPool::create()
            ->add('sleep 1')
            ->add('echo quick')
            ->waitForAll();

        $this->assertSame([0, 1], array_keys($results));
        $this->assertSame('', $results[0]->getOutput());
        $this->assertSame("quick\n", $results[1]->getOutput());
    }

    public function testCommandsAreReadWithQuotes(): void
    {
        $results = ProcessPool::create()->add('echo "two words"')->waitForAll();

        $this->assertSame("two words\n", $results[0]->getOutput());
    }

    public function testACommandCanBeAnArray(): void
    {
        $results = ProcessPool::create()->add(['echo', 'x   y'])->waitForAll();

        $this->assertSame("x   y\n", $results[0]->getOutput(), 'an array argument is passed untouched, spaces and all');
    }

    public function testTheHandlerIsCalledOncePerProcess(): void
    {
        $seen = [];

        $results = ProcessPool::create()
            ->withOutputHandler(function (ProcessResult $result) use (&$seen) {
                $seen[] = trim($result->getOutput());
            })
            ->add('echo a')
            ->add('echo b')
            ->add('echo c')
            ->waitForAll();

        sort($seen);

        $this->assertSame(['a', 'b', 'c'], $seen);
        $this->assertCount(3, $results);
    }

    public function testTheHandlerIsCalledEvenWhenEveryProcessFinishesBeforeWaitForAll(): void
    {
        $calls = 0;
        $pool = ProcessPool::create()->withOutputHandler(function () use (&$calls) {
            $calls++;
        })->add('echo a')->add('echo b');

        $pool->start();
        usleep(300000);
        $pool->waitForAll();

        $this->assertSame(2, $calls);
    }

    public function testNoMoreThanTheConcurrencyLimitRunAtOnce(): void
    {
        $limit = 2;
        $running = [];
        $pool = ProcessPool::create()->withConcurrency($limit);
        $pool->withOutputHandler(function () use ($pool, &$running) {
            $running[] = count($pool->getRunningProcesses());
        });

        foreach (range(1, 4) as $unused) {
            $pool->add('sleep 1');
        }

        $start = microtime(true);
        $results = $pool->waitForAll();
        $elapsed = microtime(true) - $start;

        $this->assertCount(4, $results);
        $this->assertLessThanOrEqual($limit, max($running));
        $this->assertGreaterThanOrEqual(1.8, $elapsed, 'four one-second jobs, two at a time, take about two seconds');
        $this->assertEmpty($pool->getRunningProcesses());
    }

    public function testAProcessThatRunsTooLongIsStoppedAndTheOthersCarryOn(): void
    {
        // The pool polled isRunning(), which never looks at timeouts, so a hung process
        // kept waitForAll() waiting forever.
        $start = microtime(true);

        $results = ProcessPool::create()
            ->withTimeout(1)
            ->add('sleep 10')
            ->add('echo finished')
            ->waitForAll();

        $this->assertLessThan(5, microtime(true) - $start);

        $this->assertTrue($results[0]->timedOut());
        $this->assertTrue($results[0]->failed());
        $this->assertFalse($results[1]->timedOut());
        $this->assertSame("finished\n", $results[1]->getOutput());
    }

    public function testTheHandlerSeesTimedOutResultsToo(): void
    {
        $timedOut = [];

        ProcessPool::create()
            ->withTimeout(1)
            ->withOutputHandler(function (ProcessResult $result) use (&$timedOut) {
                $timedOut[] = $result->timedOut();
            })
            ->add('sleep 10')
            ->add('echo ok')
            ->waitForAll();

        sort($timedOut);

        $this->assertSame([false, true], $timedOut);
    }

    public function testATimedOutProcessFreesItsSlotForTheNextOne(): void
    {
        $start = microtime(true);

        $results = ProcessPool::create()
            ->withConcurrency(1)
            ->withTimeout(1)
            ->add('sleep 10')
            ->add('echo after')
            ->waitForAll();

        $this->assertLessThan(6, microtime(true) - $start);
        $this->assertTrue($results[0]->timedOut());
        $this->assertSame("after\n", $results[1]->getOutput());
    }

    public function testResultsRecordHowLongEachProcessTook(): void
    {
        $results = ProcessPool::create()->add('sleep 1')->add('echo fast')->waitForAll();

        $this->assertGreaterThanOrEqual(0.9, $results[0]->getDuration());
        $this->assertLessThan($results[0]->getDuration(), $results[1]->getDuration());
    }

    public function testEnvironmentAndWorkingDirectoryAreUsed(): void
    {
        $results = ProcessPool::create()
            ->inDirectory(sys_get_temp_dir())
            ->withEnvironment(['ORION_POOL_VAR' => 'from-pool'])
            ->add('printenv ORION_POOL_VAR')
            ->add('pwd')
            ->waitForAll();

        $this->assertSame("from-pool\n", $results[0]->getOutput());
        $this->assertStringContainsString(realpath(sys_get_temp_dir()), trim($results[1]->getOutput()));
    }

    public function testFailuresAreReportedPerProcess(): void
    {
        $results = ProcessPool::create()->add('ls /nonexistent-orion-pool')->add('echo ok')->waitForAll();

        $this->assertTrue($results[0]->failed());
        $this->assertNotSame(0, $results[0]->getExitCode());
        $this->assertStringContainsString('nonexistent-orion-pool', $results[0]->getError());
        $this->assertTrue($results[1]->wasSuccessful());
    }

    public function testCommandsCannotBeAddedAfterStarting(): void
    {
        $pool = ProcessPool::create()->add('echo a');
        $pool->start();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot add commands after pool has started');

        $pool->add('echo b');
    }

    public function testDangerousCommandsAreRejected(): void
    {
        foreach (['echo a; echo b', "echo a\necho b", ['echo', "a\nb"], 'echo a | cat'] as $command) {
            try {
                ProcessPool::create()->add($command);
                $this->fail('Expected the command to be rejected');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Potential command injection detected', $e->getMessage());
            }
        }
    }

    public function testAnEmptyPoolFinishesImmediately(): void
    {
        $this->assertSame([], ProcessPool::create()->waitForAll());
    }
}
