<?php

namespace Doppar\Orion\Tests\Unit;

use Doppar\Orion\Process\CommandParser;
use Doppar\Orion\Process\ProcessPipeline;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

class ProcessPipelineTest extends TestCase
{
    protected function setUp(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('These pipelines use Unix tools.');
        }
    }

    /**
     * Put a command in the pipeline without the sanitizer, to see what would happen to it.
     */
    private function addUnchecked(ProcessPipeline $pipeline, string $command): ProcessPipeline
    {
        $property = new \ReflectionProperty($pipeline, 'commands');
        $commands = $property->getValue($pipeline);
        $commands[] = ['command' => $command, 'arguments' => CommandParser::parse($command)];
        $property->setValue($pipeline, $commands);

        return $pipeline;
    }

    public function testTheOutputOfEachCommandFeedsTheNext(): void
    {
        $result = ProcessPipeline::create()->add('echo hello world')->add('tr a-z A-Z')->add('cut -d " " -f 2')->execute();

        $this->assertSame("WORLD\n", $result->getOutput());
        $this->assertTrue($result->wasSuccessful());
    }

    public function testQuotedArgumentsAreReadAsQuotes(): void
    {
        $result = ProcessPipeline::create()->add('echo "a b"')->add('grep "a b"')->execute();

        $this->assertSame("a b\n", $result->getOutput());
    }

    public function testLargeErrorOutputFromAnEarlierCommandDoesNotBlockThePipeline(): void
    {
        // About 200 KB on stderr, far more than a pipe buffer holds (the command line itself
        // stays under 128 KB). The old implementation never read the stderr of any command but
        // the last, and waited for it forever.
        $missing = array_map(fn(int $i) => sprintf('/nonexistent-orion/%08d', $i), range(1, 2500));

        $start = microtime(true);
        $result = ProcessPipeline::create()->add('ls ' . implode(' ', $missing))->add('cat')->execute();

        $this->assertLessThan(20, microtime(true) - $start);
        $this->assertGreaterThan(100000, strlen($result->getError()));
        $this->assertSame('', $result->getOutput());
        $this->assertNotSame(0, $result->getExitCode());
    }

    public function testTheErrorOutputOfEveryCommandIsReturned(): void
    {
        $result = ProcessPipeline::create()
            ->add('ls /nonexistent-orion-first')
            ->add('cat')
            ->add('ls /nonexistent-orion-last')
            ->execute();

        $this->assertStringContainsString('nonexistent-orion-first', $result->getError());
        $this->assertStringContainsString('nonexistent-orion-last', $result->getError());
    }

    public function testTheExitCodeIsTheLastFailureLikeAShellWithPipefail(): void
    {
        $this->assertSame(0, ProcessPipeline::create()->add('echo a')->add('cat')->execute()->getExitCode());

        $lastFails = ProcessPipeline::create()->add('echo a')->add('sh -c "exit 3"')->execute();
        $this->assertSame(3, $lastFails->getExitCode());
        $this->assertTrue($lastFails->failed());

        $firstFails = ProcessPipeline::create()->add('sh -c "exit 4"')->add('cat')->execute();
        $this->assertSame(4, $firstFails->getExitCode(), 'a failure early in the pipeline is not hidden by a later success');

        $both = ProcessPipeline::create()->add('sh -c "exit 3"')->add('sh -c "exit 2"')->execute();
        $this->assertSame(2, $both->getExitCode(), 'the rightmost failure wins');
        $this->assertSame([3, 2], $both->getExitCodes());
    }

    public function testACommandThatCannotBeFoundFailsTheResult(): void
    {
        $result = ProcessPipeline::create()->add('definitely-not-a-real-program-xyz')->add('cat')->execute();

        $this->assertSame(127, $result->getExitCode());
        $this->assertSame([127, 0], $result->getExitCodes());
        $this->assertStringContainsString('definitely-not-a-real-program-xyz', $result->getError());
    }

    public function testAFailureIsAvailableAsAnException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('echo a | sh -c "exit 3"');

        ProcessPipeline::create()->add('echo a')->add('sh -c "exit 3"')->execute()->throw();
    }

    public function testTheResultDescribesThePipeline(): void
    {
        $result = ProcessPipeline::create()->add('echo hi')->add('tr a-z A-Z')->execute();

        $this->assertSame('echo hi | tr a-z A-Z', $result->getCommandLine());
        $this->assertIsFloat($result->getDuration());
        $this->assertSame(['HI'], $result->lines());
    }

    // ----- no shell ---------------------------------------------------------------------

    public function testALineBreakCannotStartAnotherCommand(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Potential command injection detected');

        ProcessPipeline::create()->add("echo first\necho INJECTED");
    }

    public function testCarriageReturnsAndNulBytesAreRejectedToo(): void
    {
        foreach (["echo a\r\necho b", "echo a\0b"] as $command) {
            try {
                ProcessPipeline::create()->add($command);
                $this->fail('Expected the command to be rejected');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Potential command injection detected', $e->getMessage());
            }
        }
    }

    public function testEvenWithoutTheSanitizerNothingIsInterpretedByAShell(): void
    {
        // The pipeline used to hand every command to `sh -c`, so a second command after a
        // line break, a `;` or a `$(...)` ran. Now the command is only split into arguments.
        $pipeline = ProcessPipeline::create();
        $this->addUnchecked($pipeline, "echo first\necho SECOND");
        $this->assertSame("first echo SECOND\n", $pipeline->execute()->getOutput());

        $pipeline = ProcessPipeline::create();
        $this->addUnchecked($pipeline, 'echo a; echo INJECTED $(echo SUBSTITUTED) $HOME *');
        $this->assertSame("a; echo INJECTED \$(echo SUBSTITUTED) \$HOME *\n", $pipeline->execute()->getOutput());
    }

    public function testACommandThatIsEmptyOrHasAnUnclosedQuoteIsRejectedWhenAdded(): void
    {
        foreach (['', '   ', 'echo "oops'] as $command) {
            try {
                ProcessPipeline::create()->add($command);
                $this->fail('Expected an InvalidArgumentException for: ' . $command);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ----- options ----------------------------------------------------------------------

    public function testACommandThatRunsTooLongIsStopped(): void
    {
        $this->expectException(ProcessTimedOutException::class);

        ProcessPipeline::create()->withTimeout(1)->add('echo a')->add('sleep 3')->execute();
    }

    public function testThereIsNoTimeoutUnlessOneIsSet(): void
    {
        $property = new \ReflectionProperty(ProcessPipeline::class, 'timeout');

        $this->assertNull($property->getValue(ProcessPipeline::create()), 'the old pipeline never timed out, and long pipelines must keep working');
        $this->assertSame(2, $property->getValue(ProcessPipeline::create()->withTimeout(2)));
        $this->assertSame("x\n", ProcessPipeline::create()->add('echo x')->execute()->getOutput());
    }

    public function testTheWorkingDirectoryAndEnvironmentAreUsed(): void
    {
        $result = ProcessPipeline::create()
            ->inDirectory(sys_get_temp_dir())
            ->withEnvironment(['ORION_PIPE_VAR' => 'from-pipeline'])
            ->add('printenv ORION_PIPE_VAR')
            ->execute();

        $this->assertSame("from-pipeline\n", $result->getOutput());

        $this->assertStringContainsString(
            realpath(sys_get_temp_dir()),
            trim(ProcessPipeline::create()->inDirectory(sys_get_temp_dir())->add('pwd')->execute()->getOutput())
        );
    }

    public function testAnEmptyPipelineIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No commands added to pipeline');

        ProcessPipeline::create()->execute();
    }
}
