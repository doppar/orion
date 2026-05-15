<?php

namespace Doppar\Orion\Support\Facades;

use Doppar\Orion\Process\InteractsWithCommandSanitization;
use Phaseolies\DI\Container;
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
     * Resolve an Orion service through the same pure facade pattern used by Doppar.
     *
     * @param string $accessor
     * @return mixed
     */
    protected static function resolveOrionService(string $accessor)
    {
        if (static::$app) {
            return static::$app->get($accessor);
        }

        return Container::getInstance()->get($accessor);
    }

    /**
     * Create a new process instance with command sanitization
     *
     * @param string|array $command
     * @return \Doppar\Orion\Process\ProcessService
     * @throws \InvalidArgumentException
     */
    public static function ping($command)
    {
        return static::resolveOrionService(static::getFacadeAccessor())::create(
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
        return static::resolveOrionService(static::getFacadeAccessor())::pingSilently();
    }

    /**
     * Create a new command pipeline
     *
     * @return \Doppar\Orion\Process\ProcessPipeline
     */
    public static function pipeline()
    {
        return static::resolveOrionService('orion.pipeline')::create();
    }

    /**
     * Create a new process pool
     *
     * @return \Doppar\Orion\Process\ProcessPool
     */
    public static function pool()
    {
        return static::resolveOrionService('orion.pool')::create();
    }

    /**
     * Run multiple commands concurrently
     *
     * @param array $commands
     * @param string|null $cwd
     * @return array
     * @throws \InvalidArgumentException
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
