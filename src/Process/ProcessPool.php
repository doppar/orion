<?php

namespace Doppar\Orion\Process;

class ProcessPool
{
    protected $processes = [];
    protected $cwd = null;
    protected $outputHandler = null;

    public static function create(): self
    {
        return new static();
    }

    public function inDirectory(string $cwd): self
    {
        $this->cwd = $cwd;
        return $this;
    }

    public function withOutputHandler(callable $handler): self
    {
        $this->outputHandler = $handler;
        return $this;
    }

    public function addProcess(string $command): self
    {
        $this->processes[] = ProcessService::create($command)
            ->inDirectory($this->cwd)
            ->asAsync();

        return $this;
    }

    public function start(): self
    {
        return $this;
    }

    public function getRunningProcesses(): array
    {
        return array_filter($this->processes, function ($process) {
            return $process->isRunning();
        });
    }

    public function waitForAll(): array
    {
        $results = [];
        foreach ($this->processes as $key => $process) {
            $results[$key] = $process->waitForCompletion();
        }
        return $results;
    }
}
