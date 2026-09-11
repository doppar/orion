<?php

namespace Doppar\Orion;

use Phaseolies\Launchers\GhostableLauncher;
use Phaseolies\Launchers\ServiceLauncher;
use Doppar\Orion\Process\ProcessService;
use Doppar\Orion\Process\ProcessPool;
use Doppar\Orion\Process\ProcessPipeline;

class OrionLauncher extends ServiceLauncher implements GhostableLauncher
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
    public function launch()
    {
        //
    }

    /**
     * Get the services that should ghost-load this provider.
     *
     * @return array<int, string>
     */
    public function ghosts(): array
    {
        return [
            'orion.process',
            'orion.pipeline',
            'orion.pool',
        ];
    }
}
