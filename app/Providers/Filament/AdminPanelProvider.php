<?php

namespace App\Providers\Filament;

use App\Filament\Pages\HomeDashboard;
use App\Filament\Widgets\ActiveWavesWidget;
use App\Filament\Widgets\PendingOrdersWidget;
use App\Filament\Widgets\PilotageStatsWidget;
use App\Filament\Widgets\ProductionCalendarWidget;
use App\Filament\Widgets\ProductionsSoonReadyWidget;
use App\Filament\Widgets\ReadyToStartProductionsWidget;
use App\Filament\Widgets\StockAlertsWidget;
use App\Filament\Widgets\TodaysProductionsWidget;
use App\Filament\Widgets\TodaysTasksWidget;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                'primary' => Color::Teal,
                'gray' => Color::Zinc,
                'info' => Color::Slate,
                'success' => Color::Lime,
                'danger' => Color::Orange,
                'warning' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->pages([
                HomeDashboard::class,
            ])
            ->widgets([
                PilotageStatsWidget::class,
                TodaysProductionsWidget::class,
                TodaysTasksWidget::class,
                ProductionsSoonReadyWidget::class,
                ReadyToStartProductionsWidget::class,
                StockAlertsWidget::class,
                ActiveWavesWidget::class,
                PendingOrdersWidget::class,
                ProductionCalendarWidget::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make(),
            ])
            // ->databaseNotifications()
            ->navigationGroups([
                'Achats',
                'Production',
                'Produits Finis',
                'Gestion Utilisateurs',
            ])
            ->unsavedChangesAlerts()
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => Blade::render('@fluxScripts'),
            )
            ->sidebarCollapsibleOnDesktop()
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
