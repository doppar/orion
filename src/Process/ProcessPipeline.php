<?php

namespace Doppar\Orion\Process;

use Symfony\Component\Process\Process as SymfonyProcess;

class ProcessPipeline
{
    use InteractsWithCommandSanitization;

    /**
     * @var array<int, array{command: string, arguments: array<int, string>}> The commands to execute, in order
     */
    protected $commands = [];

    /**
     * @var string|null $cwd The working directory for the commands (null means current PHP working dir)
     */
    protected $cwd = null;

    /**
     * @var array<string, string|false>|null $env Environment variables for the processes (null means inherit from PHP)
     */
    protected $env = null;

    /**
     * @var int|float|null Seconds each command may run (null, the default, means no timeout)
     */
    protected $timeout = null;

    /**
     * Static constructor for fluent interface
     *
     * @return self
     */
    public static function create(): self
    {
        return new static();
    }

    /**
     * Set the working directory for all commands in the pipeline
     *
     * @param string $cwd The working directory path
     * @return self
     */
    public function inDirectory(string $cwd): self
    {
        $this->cwd = $cwd;

        return $this;
    }

    /**
     * Set environment variables for all processes in the pipeline
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
     * Set how long each command may run
     *
     * @param int|float|null $timeout
     * @return self
     */
    public function withTimeout(int|float|null $timeout): self
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * Add a command to the pipeline
     *
     * @param string $command The command to add (will be sanitized)
     * @return self
     * @throws \InvalidArgumentException
     */
    public function add(string $command): self
    {
        static::validateCommand($command);

        $arguments = CommandParser::parse($command);

        if ($arguments === []) {
            throw new \InvalidArgumentException('A pipeline command cannot be empty.');
        }

        $this->commands[] = ['command' => $command, 'arguments' => $arguments];

        return $this;
    }

    /**
     * Execute the pipeline of commands
     *
     * @return ProcessResult The result of the pipeline execution
     * @throws \RuntimeException If no commands were added or if a process cannot be started
     * @throws \Symfony\Component\Process\Exception\ProcessTimedOutException
     */
    public function execute(): ProcessResult
    {
        if (empty($this->commands)) {
            throw new \RuntimeException("No commands added to pipeline");
        }

        $startedAt = microtime(true);
        $input = null;
        $errors = '';
        $exitCodes = [];
        $output = '';

        foreach ($this->commands as $stage) {
            $process = new SymfonyProcess($stage['arguments'], $this->cwd, $this->env, $input, $this->timeout);

            try {
                $process->run();
            } catch (\Symfony\Component\Process\Exception\ProcessStartFailedException $e) {
                throw new \RuntimeException("Failed to start process: {$stage['command']}", 0, $e);
            }

            $exitCodes[] = $process->getExitCode() ?? -1;
            $errors .= $process->getErrorOutput();
            $output = $input = $process->getOutput();
        }

        $failed = array_filter($exitCodes, fn(int $code) => $code !== 0);

        $result = new \stdClass();
        $result->output = $output;
        $result->error = $errors;
        $result->exitCode = $failed === [] ? 0 : end($failed);
        $result->exitCodes = $exitCodes;
        $result->commandLine = implode(' | ', array_column($this->commands, 'command'));
        $result->duration = microtime(true) - $startedAt;

        return new ProcessResult($result);
    }
}
