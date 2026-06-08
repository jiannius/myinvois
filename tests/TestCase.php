<?php

namespace Jiannius\Myinvois\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Jiannius\Myinvois\MyinvoisServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app) : array
    {
        return [MyinvoisServiceProvider::class];
    }

    protected function defineEnvironment($app) : void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // array cache keeps the Carbon `expired_at` object intact for token caching
        $app['config']->set('cache.default', 'array');
    }

    /**
     * Create the supporting `orders` table used as a polymorphic parent in
     * the trait / observer tests. The package's own migration is registered
     * by the service provider and runs automatically under RefreshDatabase.
     */
    protected function defineDatabaseMigrations() : void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('myinvois_status')->nullable();
            $table->string('myinvois_preprod_status')->nullable();
            $table->timestamps();
        });
    }
}
