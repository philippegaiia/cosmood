<?php

use App\Filament\Resources\Production\FormulaResource;
use App\Filament\Resources\Production\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\Production\ProductResource\ProductResource;
use App\Filament\Resources\Production\ProductTypes\Pages\CreateProductType;
use App\Filament\Resources\Production\ProductTypes\ProductTypeResource;
use App\Filament\Resources\Supply\IngredientResource;
use App\Filament\Resources\Supply\IngredientResource\Pages\CreateIngredient;
use App\Models\User;
use BezhanSalleh\LanguageSwitch\LanguageSwitch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    actingAs(User::factory()->create());
});

it('does not use raw UI literals in Filament builders', function (): void {
    $paths = [
        app_path('Filament'),
        app_path('Livewire/ProductionItemsEditor.php'),
    ];

    $files = collect($paths)
        ->flatMap(function (string $path): array {
            if (is_file($path)) {
                return [$path];
            }

            return collect(File::allFiles($path))
                ->map(fn ($file): string => $file->getPathname())
                ->values()
                ->all();
        })
        ->values();

    $patterns = [
        '/->(?:label|helperText|placeholder|hint|title|description|addActionLabel|emptyStateHeading|emptyStateDescription|modalHeading|modalDescription|modalSubmitActionLabel|modalCancelActionLabel|body)\(\'/' => 'raw builder method string',
        '/(?:Section|Fieldset|Tab|Stat)::make\(\'/' => 'raw make() heading',
    ];

    $violations = [];

    foreach ($files as $file) {
        $contents = file_get_contents($file);

        foreach ($patterns as $pattern => $description) {
            if (! preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            $line = substr_count(substr($contents, 0, $matches[0][1]), "\n") + 1;

            $violations[] = str_replace(base_path().'/', '', $file).':'.$line.' '.$description;
        }
    }

    expect($violations)->toBe([]);
});

it('configures the language switcher for french english and spanish', function (): void {
    $switch = LanguageSwitch::make();

    expect($switch->getLocales())->toBe(['fr', 'en', 'es'])
        ->and($switch->getPreferredLocale())->toBe('fr')
        ->and($switch->getLabel('fr'))->toBe('Français')
        ->and($switch->getLabel('en'))->toBe('English')
        ->and($switch->getLabel('es'))->toBe('Español');
});

it('translates representative direct string keys from json files', function (): void {
    app()->setLocale('en');

    expect(__('Vague'))->toBe('Wave')
        ->and(__('Approvisionnement'))->toBe('Procurement')
        ->and(__('Aucune commande en attente'))->toBe('No pending orders')
        ->and(__('Référence vague'))->toBe('Wave reference')
        ->and(__('La date de production doit être >= au début de vague (:date).', ['date' => '01/04/2026']))
        ->toBe('The production date must be >= at the start of the wave (01/04/2026).');

    app()->setLocale('es');

    expect(__('Vague'))->toBe('Oleada')
        ->and(__('Approvisionnement'))->toBe('Aprovisionamiento')
        ->and(__('Aucune commande en attente'))->toBe('No hay pedidos pendientes')
        ->and(__('Référence vague'))->toBe('Referencia de oleada')
        ->and(__('La date de production doit être >= au début de vague (:date).', ['date' => '01/04/2026']))
        ->toBe('La fecha de producción debe ser >= al inicio de la oleada (01/04/2026).');
});

it('resolves localized navigation labels', function (
    string $locale,
    string $referencesGroup,
    string $productsItem,
): void {
    app()->setLocale($locale);

    expect(__('navigation.groups.references'))->toBe($referencesGroup)
        ->and(__('navigation.items.products'))->toBe($productsItem);
})->with([
    'fr' => ['fr', 'Référentiels', 'Produits'],
    'en' => ['en', 'Reference Data', 'Products'],
    'es' => ['es', 'Referencias', 'Productos'],
]);

it('resolves localized resource model labels', function (
    string $locale,
    string $productTypeLabel,
    string $productLabel,
    string $ingredientLabel,
    string $formulaLabel,
): void {
    app()->setLocale($locale);

    expect(ProductTypeResource::getModelLabel())->toBe($productTypeLabel)
        ->and(ProductResource::getModelLabel())->toBe($productLabel)
        ->and(IngredientResource::getModelLabel())->toBe($ingredientLabel)
        ->and(FormulaResource::getModelLabel())->toBe($formulaLabel);
})->with([
    'fr' => ['fr', 'type de produit', 'produit', 'ingrédient', 'formule'],
    'en' => ['en', 'product type', 'product', 'ingredient', 'formula'],
    'es' => ['es', 'tipo de producto', 'producto', 'ingrediente', 'fórmula'],
]);

it('renders localized product type form labels', function (
    string $locale,
    string $sectionTitle,
    string $categoryLabel,
): void {
    app()->setLocale($locale);

    Livewire::test(CreateProductType::class)
        ->assertSee($sectionTitle)
        ->assertSee($categoryLabel);
})->with([
    'fr' => ['fr', 'Informations générales', 'Catégorie de produit'],
    'en' => ['en', 'General information', 'Product category'],
    'es' => ['es', 'Información general', 'Categoría de producto'],
]);

it('renders localized product form labels', function (
    string $locale,
    string $sectionTitle,
    string $manufacturedIngredientLabel,
): void {
    app()->setLocale($locale);

    Livewire::test(CreateProduct::class)
        ->assertSee($sectionTitle)
        ->assertSee($manufacturedIngredientLabel);
})->with([
    'fr' => ['fr', 'Classification', 'Ingrédient fabriqué'],
    'en' => ['en', 'Classification', 'Manufactured ingredient'],
    'es' => ['es', 'Clasificación', 'Ingrediente fabricado'],
]);

it('renders localized ingredient form labels', function (
    string $locale,
    string $categoryLabel,
    string $codeHelper,
): void {
    app()->setLocale($locale);

    Livewire::test(CreateIngredient::class)
        ->assertSee($categoryLabel)
        ->assertSee($codeHelper);
})->with([
    'fr' => ['fr', 'Catégorie', 'Généré automatiquement basé sur la catégorie si vide'],
    'en' => ['en', 'Category', 'Generated automatically from the category when left empty'],
    'es' => ['es', 'Categoría', 'Se genera automáticamente a partir de la categoría si se deja vacío'],
]);
