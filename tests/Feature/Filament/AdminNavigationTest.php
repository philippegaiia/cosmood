<?php

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

function navigationLabels(iterable $items): array
{
    return collect($items)
        ->map(fn (NavigationItem $item): string => $item->getLabel())
        ->values()
        ->all();
}

function findNavigationItem(NavigationGroup $group, string $label): ?NavigationItem
{
    return collect($group->getItems())
        ->first(fn (NavigationItem $item): bool => $item->getLabel() === $label);
}

it('organizes the admin navigation by workflow', function (): void {
    app()->setLocale('fr');

    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate(config('filament-shield.super_admin.name', 'super_admin')));

    actingAs($user);

    Filament::setCurrentPanel('admin');

    $navigation = collect(Filament::getNavigation())
        ->keyBy(fn (NavigationGroup $group): ?string => $group->getLabel());

    $workflowNavigation = $navigation->only([
        'Pilotage',
        'Opérations',
        'Référentiels',
        'Configuration',
    ]);

    expect($workflowNavigation->keys()->values()->all())
        ->toBe(['Pilotage', 'Opérations', 'Référentiels', 'Configuration'])
        ->and(navigationLabels($workflowNavigation['Pilotage']->getItems()))
        ->toBe(['Pilotage', 'Production', 'Achats'])
        ->and(navigationLabels(findNavigationItem($workflowNavigation['Pilotage'], 'Production')?->getChildItems() ?? []))
        ->toBe(['Planning', 'Calendrier', 'Simulateur Flash'])
        ->and(navigationLabels(findNavigationItem($workflowNavigation['Pilotage'], 'Achats')?->getChildItems() ?? []))
        ->toBe(['Pilotage achats'])
        ->and(navigationLabels($workflowNavigation['Opérations']->getItems()))
        ->toBe(['Productions', 'Commandes fournisseurs', 'Inventaire'])
        ->and(navigationLabels(findNavigationItem($workflowNavigation['Opérations'], 'Productions')?->getChildItems() ?? []))
        ->toBe(['Vagues de Production', 'Tâches'])
        ->and(navigationLabels(findNavigationItem($workflowNavigation['Opérations'], 'Inventaire')?->getChildItems() ?? []))
        ->toBe(['Mouvements de stock'])
        ->and(navigationLabels($workflowNavigation['Référentiels']->getItems()))
        ->toBe(['Produits', 'Ingrédients', 'Fournisseurs'])
        ->and(navigationLabels(findNavigationItem($workflowNavigation['Référentiels'], 'Produits')?->getChildItems() ?? []))
        ->toBe(['Types de Produit', 'Catégories Produits', 'Formules'])
        ->and(navigationLabels(findNavigationItem($workflowNavigation['Référentiels'], 'Ingrédients')?->getChildItems() ?? []))
        ->toBe(['Catégories Ingrédients'])
        ->and(navigationLabels(findNavigationItem($workflowNavigation['Référentiels'], 'Fournisseurs')?->getChildItems() ?? []))
        ->toBe(['Contacts Fournisseurs', 'Ingrédients référencés'])
        ->and(navigationLabels($workflowNavigation['Configuration']->getItems()))
        ->toBe(['Paramètres', 'Modèles de production', 'Utilisateurs'])
        ->and(navigationLabels(findNavigationItem($workflowNavigation['Configuration'], 'Paramètres')?->getChildItems() ?? []))
        ->toBe(['Jours fériés', 'Lignes de production'])
        ->and(navigationLabels(findNavigationItem($workflowNavigation['Configuration'], 'Modèles de production')?->getChildItems() ?? []))
        ->toBe(['Types de tâches', 'Modèles QC']);
});
