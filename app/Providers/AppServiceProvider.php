<?php

namespace App\Providers;

use App\Models\Production\Production;
use App\Observers\ProductionObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Model::preventLazyLoading(! app()->isProduction());

        Production::observe(ProductionObserver::class);
    }
}
