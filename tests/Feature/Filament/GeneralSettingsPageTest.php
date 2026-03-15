<?php

use App\Filament\Pages\Settings\GeneralSettingsPage;
use App\Models\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

it('saves company issuer settings for supplier documents', function () {
    actingAs(User::factory()->create());

    Livewire::test(GeneralSettingsPage::class)
        ->fillForm([
            'internal_supplier_label' => 'INT',
            'date_format' => 'd/m/Y',
            'company_name' => 'Laboratoires Horizon',
            'company_address' => "12 rue des Fleurs\n75001 Paris\nFrance",
            'company_vat_number' => 'FR12345678901',
        ])
        ->call('save')
        ->assertNotified();

    expect(Settings::companyName())->toBe('Laboratoires Horizon')
        ->and(Settings::companyAddress())->toBe("12 rue des Fleurs\n75001 Paris\nFrance")
        ->and(Settings::companyVatNumber())->toBe('FR12345678901');
});
