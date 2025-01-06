<?php

namespace Jiannius\Myinvois;

use Illuminate\Support\ServiceProvider;

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
    }
}