<?php

namespace Doppar\Orion\Process;

use Symfony\Component\Process\Process as SymfonyProcess;

class ProcessPipeline
{
    protected $commands = [];
    protected $cwd = null;
    protected $env = null;

    public static function create(): self
    {
        return new static();
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

    public function add(string $command): self
    {
        $this->commands[] = $command;
        return $this;
    }

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
