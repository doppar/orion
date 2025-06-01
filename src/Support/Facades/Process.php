<?php

namespace Doppar\Orion\Support\Facades;

use Phaseolies\Facade\BaseFacade;

class Process extends BaseFacade
{
    protected static function getFacadeAccessor()
    {
        return 'orion.process';
    }

    public static function ping($command)
    {
        return static::$app['orion.process']::create($command);
    }

    public static function pingSilently()
    {
        return static::$app['orion.process']::pingSilently();
    }

    public static function pipeline()
    {
        return static::$app['orion.pipeline']::create();
    }

    public static function pool()
    {
        return static::$app['orion.pool']::create();
    }

    public static function runConcurrently(array $commands, ?string $cwd = null)
    {
        $pool = static::pool();

        if ($cwd) {
            $pool->inDirectory($cwd);
        }

        foreach ($commands as $command) {
            $pool->addProcess($command);
        }

        return $pool->waitForAll();
    }
}
