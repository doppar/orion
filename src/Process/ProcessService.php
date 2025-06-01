<?php

namespace Doppar\Orion\Process;

use Symfony\Component\Process\Process as SymfonyProcess;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

class ProcessService
{
    protected $command;
    protected $timeout = 60;
    protected $idleTimeout = null;
    protected $cwd = null;
    protected $env = null;
    protected $input = null;
    protected $quiet = false;
    protected $outputCallback;
    protected $process;

    public function __construct($command)
    {
        $this->command = $command;
    }

    public static function create($command): self
    {
        return new static($command);
    }

    public static function pingSilently(): self
    {
        $instance = new static(null);
        $instance->quiet = true;
        return $instance;
    }

    public function withTimeout(int $timeout): self
    {
        $this->timeout = $timeout;
        return $this;
    }

    public function withIdleTimeout(int $idleTimeout): self
    {
        $this->idleTimeout = $idleTimeout;
        return $this;
    }

    public function inDirectory(string $cwd): self
    {
        $this->cwd = $cwd;
        return $this;
    }

    public function withEnvironment(array $env): self
    {
        $this->env = $env;
        return $this;
    }

    public function withInput($input): self
    {
        $this->input = $input;
        return $this;
    }

    public function withOutputHandler(callable $callback): self
    {
        $this->outputCallback = $callback;
        return $this;
    }

    public function execute($command = null): ProcessResult
    {
        if ($command !== null) {
            $this->command = $command;
        }

        $this->process = $this->createSymfonyProcess();
        $this->process->run($this->outputCallback);

        return new ProcessResult($this->process);
    }

    public function asAsync($command = null): self
    {
        if ($command !== null) {
            $this->command = $command;
        }

        $this->process = $this->createSymfonyProcess();
        $this->process->start();

        return $this;
    }

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

    public function until(callable $condition): bool
    {
        return $this->process->waitUntil($condition);
    }

    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    public function getLatestOutput(): string
    {
        return $this->process->getIncrementalOutput();
    }

    public function getLatestError(): string
    {
        return $this->process->getIncrementalErrorOutput();
    }

    public function verifyTimeout()
    {
        try {
            $this->process->checkTimeout();
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
    }

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
