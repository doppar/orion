<?php

namespace Doppar\Orion\Process;

use Doppar\Orion\Process\InteractsWithCommandSanitization;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

class ProcessPool
{
    use InteractsWithCommandSanitization;

    /**
     * How long to sleep between checks of the running processes, in microseconds.
     */
    protected const POLL_INTERVAL = 10000;

    /**
     * @var array $processes Collection of process entries with their state
     *               Each entry contains:
     *               - 'command': The command to execute
     *               - 'process': ProcessService instance or null
     *               - 'result': Process result or null
     */
    protected $processes = [];

    /**
     * @var string|null $cwd Working directory for all processes
     */
    protected $cwd = null;

    /**
     * @var array<string, string|false>|null $env Environment variables for all processes
     */
    protected $env = null;

    /**
     * @var int|float|null $timeout Seconds each process may run (null means no timeout)
     */
    protected $timeout = 60;

    /**
     * @var callable|null $outputHandler Callback to handle process output
     */
    protected $outputHandler = null;

    /**
     * @var int $maxConcurrent Maximum number of concurrent processes
     */
    protected $maxConcurrent = 5;

    /**
     * @var bool $started Flag indicating if processes have been started
     */
    protected $started = false;

    /**
     * Static constructor for fluent interface
     *
     * @return self New ProcessPool instance
     */
    public static function create(): self
    {
        return new static();
    }

    /**
     * Set the working directory for all processes
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
     * Set environment variables for all processes
     *
     * @param array<string, string|false> $env Associative array of environment variables
     * @return self
     */
    public function withEnvironment(array $env): self
    {
        $this->env = $env;

        return $this;
    }

    /**
     * Set how long each process may run. One that exceeds it is stopped, and its result
     * reports timedOut() while the others carry on.
     *
     * @param int|float|null $timeout Seconds; null or 0 means no timeout
     * @return self
     */
    public function withTimeout(int|float|null $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * Set a callback to handle process output
     *
     * @param callable $handler Function to receive process results
     * @return self
     */
    public function withOutputHandler(callable $handler): self
    {
        $this->outputHandler = $handler;

        return $this;
    }

    /**
     * Set maximum number of concurrent processes
     *
     * @param int $max Maximum concurrent processes (minimum 1)
     * @return self
     */
    public function withConcurrency(int $max): self
    {
        $this->maxConcurrent = max(1, $max);

        return $this;
    }

    /**
     * Add a command to the process pool
     *
     * @param string|array<int, string> $command Command to execute (will be sanitized)
     * @return self
     * @throws \RuntimeException If pool has already started
     */
    public function add(string|array $command): self
    {
        if ($this->started) {
            throw new \RuntimeException("Cannot add commands after pool has started");
        }

        $this->processes[] = [
            'command' => static::sanitizeCommand($command),
            'process' => null,
            'result' => null
        ];
        return $this;
    }

    /**
     * Start all processes in the pool with concurrency control
     *
     * Begins executing processes while respecting the maximum concurrency limit.
     * Processes are started as slots become available.
     *
     * @return self
     */
    public function start(): self
    {
        if ($this->started) {
            return $this;
        }

        $this->started = true;

        foreach (array_keys($this->processes) as $index) {
            while ($this->countRunning() >= $this->maxConcurrent) {
                $this->checkRunningProcesses();
                usleep(static::POLL_INTERVAL);
            }

            $service = ProcessService::create($this->processes[$index]['command'])
                ->inDirectory($this->cwd)
                ->withTimeout($this->timeout);

            if ($this->env !== null) {
                $service->withEnvironment($this->env);
            }

            $this->processes[$index]['process'] = $service->asAsync();
        }

        return $this;
    }

    /**
     * How many started processes have not been finished off yet
     *
     * @return int
     */
    protected function countRunning(): int
    {
        $running = 0;

        foreach ($this->processes as $item) {
            if ($item['process'] !== null && $item['result'] === null) {
                $running++;
            }
        }

        return $running;
    }

    /**
     * Check running processes and handle completed ones
     *
     * A process that has finished, or has gone over its timeout (it is stopped), gets its
     * result and the output handler is called once for it.
     */
    protected function checkRunningProcesses(): void
    {
        foreach ($this->processes as $index => $item) {
            if ($item['process'] === null || $item['result'] !== null) {
                continue;
            }

            /** @var ProcessService $service */
            $service = $item['process'];
            $timedOut = false;

            try {
                $service->verifyTimeout();
            } catch (ProcessTimedOutException) {
                $timedOut = true;
            }

            if (!$timedOut && $service->isRunning()) {
                continue;
            }

            $service->waitForCompletion();

            $this->processes[$index]['result'] = $service->result($timedOut);

            if ($this->outputHandler) {
                call_user_func($this->outputHandler, $this->processes[$index]['result']);
            }
        }
    }

    /**
     * Get all currently running processes
     *
     * @return array Array of running ProcessService instances
     */
    public function getRunningProcesses(): array
    {
        $running = [];
        foreach ($this->processes as $key => $item) {
            if ($item['process'] && $item['result'] === null && $item['process']->isRunning()) {
                $running[$key] = $item['process'];
            }
        }
        return $running;
    }

    /**
     * Wait for all processes in the pool to complete
     *
     * If pool hasn't started, starts it first.
     * Blocks until all processes finish, or are stopped for exceeding their timeout.
     *
     * @return array Array of results keyed by their original position
     */
    public function waitForAll(): array
    {
        if (!$this->started) {
            $this->start();
        }

        while ($this->countRunning() > 0) {
            $this->checkRunningProcesses();
            usleep(static::POLL_INTERVAL);
        }

        $results = [];

        foreach ($this->processes as $key => $item) {
            $results[$key] = $item['result'];
        }

        return $results;
    }
}
