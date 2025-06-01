<?php

namespace Doppar\Orion;

use Phaseolies\Providers\ServiceProvider;
use Doppar\Orion\Process\ProcessService;
use Doppar\Orion\Process\ProcessPool;
use Doppar\Orion\Process\ProcessPipeline;

class OrionServiceProvider extends ServiceProvider
{
    /**
     * Register services and bindings into the container.
     */
    public function register()
    {
        $this->app->singleton('orion.process', fn() => new ProcessService(null));
        $this->app->singleton('orion.pipeline', ProcessPipeline::class);
        $this->app->singleton('orion.pool', ProcessPool::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot()
    {
        //
    }
}
