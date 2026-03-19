<?php

namespace App\Providers;

use App\Models\Production\Production;
use App\Observers\ProductionObserver;
use BezhanSalleh\LanguageSwitch\LanguageSwitch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Model::preventLazyLoading(! app()->isProduction());

        LanguageSwitch::configureUsing(function (LanguageSwitch $switch): void {
            $switch
                ->locales(['fr', 'en', 'es'])
                ->nativeLabel();
        });

        Production::observe(ProductionObserver::class);
    }
}
