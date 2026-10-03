<?php

namespace Doppar\Orion\Process;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process as SymfonyProcess;

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
     * @var array<string, mixed> Facts the process object does not keep: duration, timed out, command line, stage codes
     */
    protected array $meta = [];

    /**
     * Constructor
     *
     * Handles different input formats:
     * - stdClass from ProcessPipeline
     * - array from async ProcessService
     * - Symfony Process object from sync ProcessService
     *
     * @param mixed $data The process result data
     * @param array<string, mixed> $meta
     */
    public function __construct($data, array $meta = [])
    {
        if ($data instanceof \stdClass) {
            // From ProcessPipeline
            $this->output = $data->output ?? '';
            $this->error = $data->error ?? '';
            $this->exitCode = $data->exitCode ?? -1;
            $this->process = $data;
            $meta += array_filter([
                'duration' => $data->duration ?? null,
                'commandLine' => $data->commandLine ?? null,
                'exitCodes' => $data->exitCodes ?? null,
                'timedOut' => $data->timedOut ?? null,
            ], fn($value) => $value !== null);
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

        $this->meta = $meta;
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
     * Whether the process ran and failed (a non-zero exit code, a signal, or a timeout)
     *
     * @return bool
     */
    public function failed(): bool
    {
        return $this->wasSuccessful() === false;
    }

    /**
     * Get the process exit code
     *
     * @return int The exit code from the process, -1 when it has none (killed, or not finished)
     */
    public function getExitCode(): int
    {
        return $this->process instanceof \stdClass
            ? $this->exitCode
            : ($this->process->getExitCode() ?? -1);
    }

    /**
     * The exit code of every command: one entry for a process, one per stage for a pipeline.
     *
     * @return array<int, int>
     */
    public function getExitCodes(): array
    {
        return $this->meta['exitCodes'] ?? [$this->getExitCode()];
    }

    /**
     * Whether the process was stopped because it exceeded its timeout
     *
     * @return bool
     */
    public function timedOut(): bool
    {
        return (bool) ($this->meta['timedOut'] ?? false);
    }

    /**
     * The signal that ended the process, if it was ended by one
     *
     * @return int|null
     */
    public function getSignal(): ?int
    {
        return $this->process instanceof SymfonyProcess && $this->process->hasBeenSignaled()
            ? $this->process->getTermSignal()
            : null;
    }

    /**
     * The command that was run
     *
     * @return string|null
     */
    public function getCommandLine(): ?string
    {
        return $this->process instanceof SymfonyProcess
            ? $this->process->getCommandLine()
            : ($this->meta['commandLine'] ?? null);
    }

    /**
     * How long the process ran, in seconds
     *
     * @return float|null Null when it was not measured
     */
    public function getDuration(): ?float
    {
        return isset($this->meta['duration']) ? (float) $this->meta['duration'] : null;
    }

    /**
     * The output as a list of lines, without the line breaks
     *
     * @return array<int, string>
     */
    public function lines(): array
    {
        $output = rtrim($this->getOutput(), "\r\n");

        return $output === '' ? [] : (preg_split('/\R/', $output) ?: []);
    }

    /**
     * Decode the output as JSON
     *
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     * @throws \JsonException
     */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        $decoded = json_decode($this->getOutput(), true, 512, JSON_THROW_ON_ERROR);

        if ($key === null) {
            return $decoded;
        }

        foreach (explode('.', $key) as $segment) {
            if (!is_array($decoded) || !array_key_exists($segment, $decoded)) {
                return $default;
            }

            $decoded = $decoded[$segment];
        }

        return $decoded;
    }

    /**
     * Throw if the process failed, so a failure cannot be ignored
     *
     * @return $this
     * @throws ProcessFailedException
     * @throws \RuntimeException
     */
    public function throw(): static
    {
        if (!$this->failed()) {
            return $this;
        }

        if ($this->process instanceof SymfonyProcess) {
            throw new ProcessFailedException($this->process);
        }

        throw new \RuntimeException(sprintf(
            'The command%s failed with exit code %d.%s',
            $this->getCommandLine() !== null ? ' "' . $this->getCommandLine() . '"' : '',
            $this->getExitCode(),
            $this->getError() !== '' ? "\n\nError output:\n" . $this->getError() : ''
        ));
    }

    /**
     * Throw if the process failed and the condition holds
     *
     * @param bool|callable $condition
     * @return $this
     */
    public function throwIf(bool|callable $condition): static
    {
        $condition = is_callable($condition) ? $condition($this) : $condition;

        return $condition ? $this->throw() : $this;
    }
}
