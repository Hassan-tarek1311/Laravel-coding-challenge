<?php

namespace App\Providers;

use App\Jobs\ExpireHoldsJob;
use Illuminate\Support\ServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            $schedule->job(new ExpireHoldsJob)->everyThirtySeconds()->withoutOverlapping();
        });
    }
}
