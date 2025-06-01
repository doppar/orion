<?php

namespace Doppar\Orion\Process;

class ProcessPipeline
{
    use InteractsWithCommandSanitization;

    /**
     * @var array $commands The list of commands to execute in the pipeline
     */
    protected $commands = [];

    /**
     * @var string|null $cwd The working directory for the commands (null means current PHP working dir)
     */
    protected $cwd = null;

    /**
     * @var array|null $env Environment variables for the processes (null means inherit from PHP)
     */
    protected $env = null;

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
     * @param array $env Associative array of environment variables
     * @return self
     */
    public function withEnvironment(array $env): self
    {
        $this->env = $env;
        return $this;
    }

    /**
     * Add a command to the pipeline
     *
     * @param string $command The command to add (will be sanitized)
     * @return self
     */
    public function add(string $command): self
    {
        $this->commands[] = static::sanitizeCommand($command);
        return $this;
    }

    /**
     * Execute the pipeline of commands
     *
     * Creates a chain of processes where each process's output is piped
     * to the next process's input. Returns the combined result.
     *
     * @return ProcessResult The result of the pipeline execution
     * @throws \RuntimeException If no commands were added or if process creation fails
     */
    public function execute(): ProcessResult
    {
        if (empty($this->commands)) {
            throw new \RuntimeException("No commands added to pipeline");
        }

        $processes = [];
        $exitCodes = [];

        // Create first process with input pipe (not STDIN)
        $firstDescriptors = [
            0 => ['pipe', 'r'],  // Input pipe
            1 => ['pipe', 'w'],  // Pipe for stdout
            2 => ['pipe', 'w']   // Pipe for stderr
        ];

        $firstPipes = [];
        $firstProcess = proc_open($this->commands[0], $firstDescriptors, $firstPipes, $this->cwd, $this->env);
        if (!is_resource($firstProcess)) {
            throw new \RuntimeException("Failed to start process: {$this->commands[0]}");
        }

        // Close input pipe since we won't be writing to it
        fclose($firstPipes[0]);

        $processes[] = [
            'process' => $firstProcess,
            'pipes' => $firstPipes
        ];

        // Create subsequent processes with pipes
        for ($i = 1; $i < count($this->commands); $i++) {
            $prevPipes = end($processes)['pipes'];

            $descriptors = [
                0 => ['pipe', 'r'],  // Will receive output from previous process
                1 => ['pipe', 'w'],  // Pipe for stdout
                2 => ['pipe', 'w']   // Pipe for stderr
            ];

            $currentPipes = [];
            $currentProcess = proc_open($this->commands[$i], $descriptors, $currentPipes, $this->cwd, $this->env);
            if (!is_resource($currentProcess)) {
                throw new \RuntimeException("Failed to start process: {$this->commands[$i]}");
            }

            // Connect previous process output to current process input
            stream_copy_to_stream($prevPipes[1], $currentPipes[0]);

            // Close the pipes
            fclose($prevPipes[1]);
            fclose($currentPipes[0]);

            $processes[] = [
                'process' => $currentProcess,
                'pipes' => $currentPipes
            ];
        }

        // Get output from last process
        $lastProcess = end($processes);
        $output = stream_get_contents($lastProcess['pipes'][1]);
        $error = stream_get_contents($lastProcess['pipes'][2]);

        // Close all pipes
        foreach ($processes as $proc) {
            foreach ($proc['pipes'] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        }

        // Get exit codes
        foreach ($processes as $proc) {
            $status = proc_get_status($proc['process']);
            $exitCodes[] = $status['exitcode'] ?? -1;
            proc_close($proc['process']);
        }

        // Create result object
        $result = new \stdClass();
        $result->output = $output ?: '';
        $result->error = $error ?: '';
        $result->exitCode = max($exitCodes);

        return new ProcessResult($result);
    }
}
