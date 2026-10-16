<?php

namespace Jiannius\Myinvois;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Jiannius\Myinvois\Console\SyncCommand;

class MyinvoisServiceProvider extends ServiceProvider
{
    // register
    public function register() : void
    {
        //
    }

    // boot
    public function boot() : void
    {
        $this->app->bind('myinvois', fn($app) => new \Jiannius\Myinvois\Myinvois());
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([SyncCommand::class]);
        }

        // Off unless the app opts in with services.myinvois.sync_schedule ('hourly', or a
        // cron expression). Read when the schedule is resolved, so config is loaded.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $frequency = config('services.myinvois.sync_schedule');

            if (! is_string($frequency) || trim($frequency) === '') return;

            $event = $schedule->command('myinvois:sync');

            if (trim($frequency) === 'hourly') $event->hourly();
            else $event->cron(trim($frequency));

            $event->withoutOverlapping(30)->onOneServer();
        });
    }
}
