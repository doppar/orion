<?php

namespace Doppar\Orion\Support\Facades;

use Doppar\Orion\Process\InteractsWithCommandSanitization;
use Phaseolies\Facade\BaseFacade;

class Process extends BaseFacade
{
    use InteractsWithCommandSanitization;

    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'orion.process';
    }

    /**
     * Create a new process instance with command sanitization
     *
     * @param string|array $command The command to execute (string will be exploded)
     * @return \Doppar\Orion\Process\ProcessService
     * @throws \InvalidArgumentException If command contains dangerous characters
     */
    public static function ping($command)
    {
        return static::$app['orion.process']::create(
            static::sanitizeCommand($command)
        );
    }

    /**
     * Create a process instance with suppressed output
     *
     * @return \Doppar\Orion\Process\ProcessService
     */
    public static function pingSilently()
    {
        return static::$app['orion.process']::pingSilently();
    }

    /**
     * Create a new command pipeline
     *
     * @return \Doppar\Orion\Process\ProcessPipeline
     */
    public static function pipeline()
    {
        return static::$app['orion.pipeline']::create();
    }

    /**
     * Create a new process pool
     *
     * @return \Doppar\Orion\Process\ProcessPool
     */
    public static function pool()
    {
        return static::$app['orion.pool']::create();
    }

    /**
     * Run multiple commands concurrently
     *
     * @param array $commands Array of commands to execute
     * @param string|null $cwd Working directory
     * @return array Array of ProcessResult objects
     * @throws \InvalidArgumentException If any command contains dangerous characters
     */
    public static function asConcurrently(array $commands, ?string $cwd = null)
    {
        $pool = static::pool();

        if ($cwd) {
            $pool->inDirectory($cwd);
        }

        foreach ($commands as $command) {
            $pool->add(
                static::sanitizeCommand($command)
            );
        }

        return $pool->waitForAll();
    }
}
