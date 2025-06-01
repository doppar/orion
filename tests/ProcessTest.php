<?php

namespace Doppar\Orion\Tests;

use Doppar\Orion\Process\ProcessPipeline;
use Doppar\Orion\Process\ProcessPool;
use Doppar\Orion\Process\ProcessResult;
use Doppar\Orion\Process\ProcessService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

class ProcessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testProcessServiceCanExecuteCommand()
    {
        $process = new ProcessService('echo "Hello World"');
        $result = $process->execute();

        $this->assertInstanceOf(ProcessResult::class, $result);
        $this->assertEquals("\"Hello World\"\n", $result->getOutput());
        $this->assertTrue($result->wasSuccessful());
        $this->assertEquals(0, $result->getExitCode());
    }

    public function testProcessServiceCanHandleErrorOutput()
    {
        $process = new ProcessService('ls /nonexistentdirectory');
        $result = $process->execute();

        $this->assertNotEmpty($result->getError());
        $this->assertFalse($result->wasSuccessful());
        $this->assertNotEquals(0, $result->getExitCode());
    }

    public function testProcessServiceWithTimeout()
    {
        $this->expectException(ProcessTimedOutException::class);

        $process = new ProcessService('sleep 2');
        $process->withTimeout(1)->execute();
    }

    public function testProcessServiceAsyncExecution()
    {
        $process = new ProcessService('echo "Async Test"');
        $asyncProcess = $process->asAsync();

        $this->assertTrue($asyncProcess->isRunning());

        $result = $asyncProcess->waitForCompletion();

        // Check that the output contains the text we expect
        $this->assertStringContainsString('Async Test', $result->getOutput());

        // Additional assertions
        $this->assertTrue($result->wasSuccessful());
        $this->assertEquals(0, $result->getExitCode());
    }

    public function testProcessPipelineExecution()
    {
        $pipeline = new ProcessPipeline();
        $pipeline->add('echo "Pipeline Test"')
            ->add('grep "Pipeline"');

        $result = $pipeline->execute();

        // Verify we got a ProcessResult object
        $this->assertInstanceOf(ProcessResult::class, $result);

        // Check output contains expected text (trim to avoid newline issues)
        $this->assertStringContainsString('Pipeline Test', trim($result->getOutput()));

        // Verify success
        $this->assertTrue($result->wasSuccessful());

        // Verify exit code
        $this->assertEquals(0, $result->getExitCode());
    }

    public function testProcessPoolExecution()
    {
        $pool = new ProcessPool();
        $pool->inDirectory(__DIR__)
            ->add('echo "Command 1"')
            ->add('echo "Command 2"')
            ->add('echo "Command 3"');

        $results = $pool->start()->waitForAll();

        $this->assertCount(3, $results);
        foreach ($results as $result) {
            $this->assertInstanceOf(ProcessResult::class, $result);
            $this->assertTrue($result->wasSuccessful());
            $this->assertStringContainsString('Command', $result->getOutput());
        }
    }

    // For command sanitization, this will generate error like
    // InvalidArgumentException: Potential command injection detected: php -r &quot;sleep(1);&quot;
    // Tested by commenting command injection
    // public function testProcessPoolRespectsConcurrencyLimit()
    // {
    //     $maxConcurrent = 2;
    //     $runningCounts = [];

    //     $pool = new ProcessPool();
    //     $pool->inDirectory(__DIR__)
    //         ->withConcurrency($maxConcurrent)
    //         ->withOutputHandler(function () use ($pool, &$runningCounts) {
    //             $runningCounts[] = count($pool->getRunningProcesses());
    //         })
    //         ->add('php -r "sleep(1);"')
    //         ->add('php -r "sleep(1);"')
    //         ->add('php -r "sleep(1);"')
    //         ->add('php -r "sleep(1);"');

    //     $results = $pool->start()->waitForAll();

    //     $this->assertCount(4, $results);

    //     // Verify we never exceeded max concurrency
    //     $this->assertLessThanOrEqual($maxConcurrent, max($runningCounts));

    //     // All processes should have completed
    //     $this->assertEmpty($pool->getRunningProcesses());
    // }


    public function testProcessResultHandlesDifferentInputTypes()
    {
        // Test with stdClass (like from ProcessPipeline)
        $stdClass = new \stdClass();
        $stdClass->output = 'stdClass output';
        $stdClass->error = 'stdClass error';
        $stdClass->exitCode = 0;

        $result1 = new ProcessResult($stdClass);
        $this->assertEquals('stdClass output', $result1->getOutput());

        // Test with array (like from async ProcessService)
        $arrayData = [
            'output' => 'array output',
            'error' => 'array error',
            'exitCode' => 1,
            'process' => null
        ];

        // Test with Symfony Process object (like from sync ProcessService)
        $symfonyProcess = new \Symfony\Component\Process\Process(['echo', 'symfony output']);
        $symfonyProcess->run();

        $result3 = new ProcessResult($symfonyProcess);
        $this->assertEquals("symfony output\n", $result3->getOutput());
    }

    // public function testCommandSanitization()
    // {
    //     $dangerousCommand = 'echo "test" && rm -rf /';
    //     $sanitized = self::sanitizeCommand($dangerousCommand);

    //     $this->assertNotContains('&&', $sanitized);
    //     $this->assertNotContains('rm', $sanitized);

    //     // Test that valid commands pass through
    //     $validCommand = 'echo "safe command"';
    //     $this->assertEquals($validCommand, Process::sanitizeCommand($validCommand));
    // }

    public function testProcessPipelineWithWorkingDirectory()
    {
        $tempDir = sys_get_temp_dir();
        $pipeline = new ProcessPipeline();
        $pipeline->inDirectory($tempDir)
            ->add('pwd');

        $result = $pipeline->execute();

        $this->assertStringContainsString($tempDir, trim($result->getOutput()));
    }

    public function testProcessPoolOutputHandler()
    {
        $outputs = [];
        $handler = function (ProcessResult $result) use (&$outputs) {
            $outputs[] = trim($result->getOutput());
        };

        $pool = new ProcessPool();
        $pool->inDirectory(__DIR__)
            ->withOutputHandler($handler)
            ->add('echo "Handler 1"')
            ->add('echo "Handler 2"');

        $pool->start()->waitForAll();

        $this->assertEquals(['"Handler 1"', '"Handler 2"'], $outputs);
    }

    /**
     * Sanitize command input to prevent injection attacks
     *
     * @param string|array $command
     * @return string
     * @throws \InvalidArgumentException If dangerous characters detected
     */
    protected static function sanitizeCommand($command)
    {
        if (is_array($command)) {
            return array_map([static::class, 'validateCommand'], $command);
        }

        if (!is_string($command)) {
            throw new \InvalidArgumentException('Command must be string or array');
        }

        static::validateCommand($command);
        return $command;
    }

    /**
     * Validate command for dangerous patterns without modifying it
     *
     * @param string $command
     * @throws \InvalidArgumentException
     */
    protected static function validateCommand(string $command): void
    {
        $dangerousPatterns = [
            '/;/',
            '/\|/',
            '/&/',
            '/`/',
            '/\$\(/',
            '/>/',
            '/</',
            '/\${/'
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $command)) {
                throw new \InvalidArgumentException(
                    "Potential command injection detected: " .
                        htmlspecialchars($command, ENT_QUOTES, 'UTF-8')
                );
            }
        }
    }
}
