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
     * @var int|float|null Process timeout in seconds (default: 60, null means no timeout)
     */
    protected $timeout = 60;

    /**
     * @var int|float|null Idle timeout in seconds (null means no idle timeout)
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
     * @var SymfonyProcess|null The Symfony Process instance, once it has been started
     */
    protected $process;

    /**
     * @var float|null When the process was started, as a microtime
     */
    protected $startedAt = null;

    /**
     * @var float|null How long the process ran, in seconds, once it has finished
     */
    protected $duration = null;

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
     * @param int|float|null $timeout Timeout in seconds; null or 0 means no timeout
     * @return self
     */
    public function withTimeout(int|float|null $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * Let the process run for as long as it needs
     *
     * @return self
     */
    public function withoutTimeout(): self
    {
        $this->timeout = null;

        return $this;
    }

    /**
     * Set the idle timeout: how long the process may go without producing output
     *
     * @param int|float|null $idleTimeout
     * @return self
     */
    public function withIdleTimeout(int|float|null $idleTimeout): self
    {
        $this->idleTimeout = $idleTimeout;

        return $this;
    }

    /**
     * Set the working directory
     *
     * @param string|null $cwd
     * @return self
     */
    public function inDirectory(?string $cwd): self
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
     * @throws \Symfony\Component\Process\Exception\ProcessTimedOutException
     */
    public function execute($command = null): ProcessResult
    {
        if ($command !== null) {
            $this->command = $command;
        }

        $this->process = $this->createSymfonyProcess();
        $this->startedAt = microtime(true);

        try {
            $this->process->run($this->outputCallback);
        } finally {
            $this->duration = microtime(true) - $this->startedAt;
        }

        return $this->result();
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
        $this->duration = null;
        $this->startedAt = microtime(true);
        $this->process->start($this->outputCallback);

        return $this;
    }

    /**
     * Wait for async process to complete
     *
     * @param callable|null $callback
     * @return ProcessResult
     * @throws \Symfony\Component\Process\Exception\ProcessTimedOutException
     */
    public function waitForCompletion(?callable $callback = null): ProcessResult
    {
        $process = $this->started();

        try {
            $process->wait($callback);
        } finally {
            $this->duration ??= $this->startedAt !== null ? microtime(true) - $this->startedAt : null;
        }

        return $this->result();
    }

    /**
     * The result of the process as it stands: final once it has finished, with an exit code
     * of -1 while it is still running.
     *
     * @param bool $timedOut
     * @return ProcessResult
     */
    public function result(bool $timedOut = false): ProcessResult
    {
        $process = $this->started();

        $duration = $this->duration
            ?? ($this->startedAt !== null && !$process->isRunning() ? microtime(true) - $this->startedAt : null);

        return new ProcessResult($process, [
            'duration' => $duration,
            'timedOut' => $timedOut,
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
        return $this->started()->waitUntil($condition);
    }

    /**
     * Check if process is running
     *
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->started()->isRunning();
    }

    /**
     * The process id, while it is running
     *
     * @return int|null
     */
    public function getPid(): ?int
    {
        return $this->started()->getPid();
    }

    /**
     * Send a signal to the running process
     *
     * @param int $signal
     * @return self
     */
    public function signal(int $signal): self
    {
        $this->started()->signal($signal);

        return $this;
    }

    /**
     * Stop the process: it is asked to end, and killed if it has not after the timeout
     *
     * @param int|float $timeout
     * @param int|null $signal
     * @return int|null
     */
    public function stop(int|float $timeout = 10, ?int $signal = null): ?int
    {
        return $this->started()->stop($timeout, $signal);
    }

    /**
     * Get incremental output
     *
     * @return string
     */
    public function getLatestOutput(): string
    {
        return $this->started()->getIncrementalOutput();
    }

    /**
     * Get incremental error output
     *
     * @return string
     */
    public function getLatestError(): string
    {
        return $this->started()->getIncrementalErrorOutput();
    }

    /**
     * Verify if process has timed out. A process that is polled with isRunning() is not
     * stopped by its timeout; this checks it, stops it if it is over, and throws.
     *
     * @throws \Symfony\Component\Process\Exception\ProcessTimedOutException
     * @throws \Symfony\Component\Process\Exception\ProcessSignaledException
     */
    public function verifyTimeout()
    {
        $this->started()->checkTimeout();
    }

    /**
     * The process, or a clear error when nothing has been started yet
     *
     * @return SymfonyProcess
     * @throws \LogicException
     */
    protected function started(): SymfonyProcess
    {
        if ($this->process === null) {
            throw new \LogicException('The process has not been started. Call execute() or asAsync() first.');
        }

        return $this->process;
    }

    /**
     * Create the Symfony Process instance
     *
     * @return SymfonyProcess
     */
    protected function createSymfonyProcess(): SymfonyProcess
    {
        $arguments = is_array($this->command)
            ? $this->command
            : ($this->command === null ? [] : CommandParser::parse((string) $this->command));

        if ($arguments === []) {
            throw new \LogicException('There is no command to run.');
        }

        $process = new SymfonyProcess(
            $arguments,
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
