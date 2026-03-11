<?php

use App\Filament\Widgets\UrgencesWidget;
use App\Models\Production\Production;
use App\Models\Production\ProductionTask;
use App\Models\Production\ProductionWave;
use App\Models\Supply\SupplierOrder;
use App\Models\User;
use Livewire\Livewire;

it('renders the manager urgency sections with actionable records', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $blockedProduction = Production::factory()->confirmed()->create([
        'production_date' => today()->toDateString(),
    ]);

    ProductionTask::factory()->create([
        'production_id' => $blockedProduction->id,
        'scheduled_date' => now()->subDay()->toDateString(),
        'is_finished' => false,
        'cancelled_at' => null,
        'name' => 'Contrôle visuel',
    ]);

    SupplierOrder::factory()->passed()->create([
        'delivery_date' => now()->subDay()->toDateString(),
        'order_ref' => 'PO-URG-001',
    ]);

    SupplierOrder::factory()->confirmed()->create([
        'delivery_date' => now()->subDay()->toDateString(),
        'order_ref' => 'PO-URG-002',
    ]);

    ProductionWave::factory()->approved()->create([
        'name' => 'Vague Risque',
    ]);

    Livewire::test(UrgencesWidget::class)
        ->assertSee('Urgences')
        ->assertSee('Blocages production')
        ->assertSee('Achats à traiter')
        ->assertSee('Tâches à rattraper')
        ->assertSee('Vagues sous tension')
        ->assertSee('points à traiter')
        ->assertSee('Voir planning')
        ->assertSee('Voir achats')
        ->assertSee('Voir production')
        ->assertSee('Voir couverture')
        ->assertSee('PO-URG-001')
        ->assertSee('PO-URG-002')
        ->assertSee('Vague Risque')
        ->assertSee('À relancer')
        ->assertSeeInOrder(['PO-URG-002', 'PO-URG-001']);
});

it('normalizes non-scalar urgency label parts without crashing', function (): void {
    $widget = new class extends UrgencesWidget
    {
        public function buildLabelForTest(mixed $primary, mixed $secondary, string $primaryFallback, string $secondaryFallback): string
        {
            return $this->buildContextLabel($primary, $secondary, $primaryFallback, $secondaryFallback);
        }
    };

    expect($widget->buildLabelForTest(['Controle', 'Visuel'], ['Savon', 'Curcuma'], 'Tâche', 'Production'))
        ->toBe('Controle / Visuel - Savon / Curcuma')
        ->and($widget->buildLabelForTest([], null, 'Tâche', 'Production'))
        ->toBe('Tâche - Production');
});
