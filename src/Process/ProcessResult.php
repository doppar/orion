<?php

namespace Doppar\Orion\Process;

class ProcessResult
{
    protected $process;
    protected $output;
    protected $error;
    protected $exitCode;

    public function __construct($data)
    {
        if ($data instanceof \stdClass) {
            // From ProcessPipeline
            $this->output = $data->output ?? '';
            $this->error = $data->error ?? '';
            $this->exitCode = $data->exitCode ?? -1;
        } elseif (is_array($data)) {
            // From async ProcessService
            $this->output = $data['output'] ?? '';
            $this->error = $data['error'] ?? '';
            $this->exitCode = $data['exitCode'] ?? -1;
            $this->process = $data['process'] ?? null;
        } else {
            // From sync ProcessService (Symfony Process object)
            $this->process = $data;
        }
    }

    public function getOutput(): string
    {
        return $this->process instanceof \stdClass
            ? $this->output
            : $this->process->getOutput();
    }

    public function getError(): string
    {
        return $this->process instanceof \stdClass
            ? $this->error
            : $this->process->getErrorOutput();
    }

    public function wasSuccessful(): bool
    {
        return $this->process instanceof \stdClass
            ? $this->exitCode === 0
            : $this->process->isSuccessful();
    }

    public function getExitCode(): int
    {
        return $this->process instanceof \stdClass
            ? $this->exitCode
            : $this->process->getExitCode();
    }
}
