<?php

namespace Doppar\Orion\Process;

use Doppar\Orion\Process\InteractsWithCommandSanitization;

class ProcessPool
{
    use InteractsWithCommandSanitization;

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
        // Fixed typo in property name
        $this->maxConcurrent = max(1, $max);

        return $this;
    }

    /**
     * Add a command to the process pool
     *
     * @param string $command Command to execute (will be sanitized)
     * @return self
     * @throws \RuntimeException If pool has already started
     */
    public function add(string $command): self
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
        $running = 0;

        foreach ($this->processes as &$item) {
            while ($running >= $this->maxConcurrent) {
                $this->checkRunningProcesses($running);
                // Sleep for 100ms to avoid busy waiting
                usleep(100000);
            }

            $item['process'] = ProcessService::create($item['command'])
                ->inDirectory($this->cwd)
                ->asAsync();
            $running++;
        }

        return $this;
    }

    /**
     * Check running processes and handle completed ones
     *
     * @param int &$runningCount Reference to running process count (will be decremented)
     */
    protected function checkRunningProcesses(&$runningCount): void
    {
        foreach ($this->processes as &$item) {
            if ($item['process'] && !isset($item['result']) && !$item['process']->isRunning()) {
                $item['result'] = $item['process']->waitForCompletion();
                $runningCount--;

                if ($this->outputHandler) {
                    call_user_func($this->outputHandler, $item['result']);
                }
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
            if ($item['process'] && $item['process']->isRunning()) {
                $running[$key] = $item['process'];
            }
        }
        return $running;
    }

    /**
     * Wait for all processes in the pool to complete
     *
     * If pool hasn't started, starts it first.
     * Blocks until all processes finish execution.
     *
     * @return array Array of results keyed by their original position
     */
    public function waitForAll(): array
    {
        if (!$this->started) {
            $this->start();
        }

        $results = [];
        $running = count($this->getRunningProcesses());

        while ($running > 0) {
            $this->checkRunningProcesses($running);
            // Sleep for 100ms between checks
            usleep(100000);
        }

        // Collect all results
        foreach ($this->processes as $key => $item) {
            if (!isset($item['result']) && $item['process']) {
                $item['result'] = $item['process']->waitForCompletion();
            }
            $results[$key] = $item['result'] ?? null;
        }

        return $results;
    }
}
