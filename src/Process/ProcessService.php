<?php

namespace Doppar\Orion\Process;

use Symfony\Component\Process\Process as SymfonyProcess;

class ProcessService
{
    /**
     * The command to execute (either as string or array of arguments)
     * @var string|array
     */
    protected $command;

    /**
     * @var int Process timeout in seconds (default: 60)
     */
    protected $timeout = 60;

    /**
     * @var int|null Idle timeout in seconds (null means no idle timeout)
     */
    protected $idleTimeout = null;

    /**
     * @var string|null Current working directory for the process
     */
    protected $cwd = null;

    /**
     * @var array|null Environment variables for the process
     */
    protected $env = null;

    /**
     * @var mixed Input to pass to the process (STDIN)
     */
    protected $input = null;

    /**
     * @var bool Whether to suppress process output
     */
    protected $quiet = false;

    /**
     * @var callable|null Callback for handling process output
     */
    protected $outputCallback;

    /**
     * @var SymfonyProcess The Symfony Process instance
     */
    protected $process;

    /**
     * @param string|array $command The command to execute
     */
    public function __construct($command)
    {
        $this->command = $command;
    }

    /**
     * Create a new ProcessService instance
     *
     * @param string|array $command The command to execute
     * @return self
     */
    public static function create($command): self
    {
        return new static($command);
    }

    /**
     * Create a ProcessService instance with output disabled
     *
     * @return self
     */
    public static function pingSilently(): self
    {
        $instance = new static(null);
        $instance->quiet = true;

        return $instance;
    }

    /**
     * Set the process timeout
     *
     * @param int $timeout Timeout in seconds
     * @return self
     */
    public function withTimeout(int $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * Set the idle timeout
     *
     * @param int $idleTimeout Idle timeout in seconds
     * @return self
     */
    public function withIdleTimeout(int $idleTimeout): self
    {
        $this->idleTimeout = $idleTimeout;

        return $this;
    }

    /**
     * Set the working directory
     *
     * @param string $cwd Working directory path
     * @return self
     */
    public function inDirectory(string $cwd): self
    {
        $this->cwd = $cwd;

        return $this;
    }

    /**
     * Set environment variables
     *
     * @param array $env Array of environment variables
     * @return self
     */
    public function withEnvironment(array $env): self
    {
        $this->env = $env;

        return $this;
    }

    /**
     * Set process input (STDIN)
     *
     * @param mixed $input Input to pass to the process
     * @return self
     */
    public function withInput($input): self
    {
        $this->input = $input;

        return $this;
    }

    /**
     * Set output handler callback
     *
     * @param callable $callback Function to handle process output
     * @return self
     */
    public function withOutputHandler(callable $callback): self
    {
        $this->outputCallback = $callback;

        return $this;
    }

    /**
     * Execute the process synchronously
     *
     * @param string|array|null $command Optional command to override
     * @return ProcessResult
     */
    public function execute($command = null): ProcessResult
    {
        if ($command !== null) {
            $this->command = $command;
        }

        $this->process = $this->createSymfonyProcess();
        $this->process->run($this->outputCallback);

        return new ProcessResult($this->process);
    }

    /**
     * Execute the process asynchronously
     *
     * @param string|array|null $command Optional command to override
     * @return self
     */
    public function asAsync($command = null): self
    {
        if ($command !== null) {
            $this->command = $command;
        }

        $this->process = $this->createSymfonyProcess();
        $this->process->start();

        return $this;
    }

    /**
     * Wait for async process to complete
     *
     * @return ProcessResult
     */
    public function waitForCompletion(): ProcessResult
    {
        $this->process->wait();

        $this->process->wait();

        return new ProcessResult([
            'output' => $this->process->getOutput(),
            'error' => $this->process->getErrorOutput(),
            'exitCode' => $this->process->getExitCode(),
            'process' => $this->process
        ]);
    }

    /**
     * Wait until a condition is met
     *
     * @param callable $condition Callback that returns bool when condition is met
     * @return bool
     */
    public function until(callable $condition): bool
    {
        return $this->process->waitUntil($condition);
    }

    /**
     * Check if process is running
     *
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    /**
     * Get incremental output
     *
     * @return string
     */
    public function getLatestOutput(): string
    {
        return $this->process->getIncrementalOutput();
    }

    /**
     * Get incremental error output
     *
     * @return string
     */
    public function getLatestError(): string
    {
        return $this->process->getIncrementalErrorOutput();
    }

    /**
     * Verify if process has timed out
     *
     * @throws \Exception If process has timed out
     */
    public function verifyTimeout()
    {
        try {
            $this->process->checkTimeout();
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }

    /**
     * Create the Symfony Process instance
     *
     * @return SymfonyProcess
     */
    protected function createSymfonyProcess(): SymfonyProcess
    {
        $process = new SymfonyProcess(
            is_array($this->command) ? $this->command : explode(' ', $this->command),
            $this->cwd,
            $this->env,
            $this->input,
            $this->timeout
        );

        if ($this->idleTimeout !== null) {
            $process->setIdleTimeout($this->idleTimeout);
        }

        if ($this->quiet) {
            $process->disableOutput();
        }

        return $process;
    }
}
