<?php

namespace App\Providers;

use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
use App\Observers\ProductionItemObserver;
use App\Observers\ProductionObserver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void {}

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading(! app()->isProduction());

        Production::observe(ProductionObserver::class);
        ProductionItem::observe(ProductionItemObserver::class);
    }
}
