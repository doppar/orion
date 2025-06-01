<?php

namespace Doppar\Orion\Process;

class ProcessResult
{
    /**
     * @var mixed The original process object/data
     */
    protected $process;

    /**
     * @var string Process output content
     */
    protected $output;

    /**
     * @var string Process error content
     */
    protected $error;

    /**
     * @var int Process exit code
     */
    protected $exitCode;

    /**
     * Constructor
     *
     * Handles different input formats:
     * - stdClass from ProcessPipeline
     * - array from async ProcessService
     * - Symfony Process object from sync ProcessService
     *
     * @param mixed $data The process result data
     */
    public function __construct($data)
    {
        if ($data instanceof \stdClass) {
            // From ProcessPipeline
            $this->output = $data->output ?? '';
            $this->error = $data->error ?? '';
            $this->exitCode = $data->exitCode ?? -1;
            $this->process = $data;
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

    /**
     * Get the process output content
     *
     * @return string The output from the process execution
     */
    public function getOutput(): string
    {
        return $this->process instanceof \stdClass
            ? $this->output
            : $this->process->getOutput();
    }

    /**
     * Get the process error output
     *
     * @return string The error output from the process execution
     */
    public function getError(): string
    {
        return $this->process instanceof \stdClass
            ? $this->error
            : $this->process->getErrorOutput();
    }

    /**
     * Check if the process executed successfully
     *
     * @return bool|null True if successful, false if failed, null if unknown
     */
    public function wasSuccessful(): ?bool
    {
        return $this->process instanceof \stdClass
            ? $this->exitCode === 0
            : $this->process?->isSuccessful();
    }

    /**
     * Get the process exit code
     *
     * @return int The exit code from the process
     */
    public function getExitCode(): int
    {
        return $this->process instanceof \stdClass
            ? $this->exitCode
            : $this->process->getExitCode();
    }
}
