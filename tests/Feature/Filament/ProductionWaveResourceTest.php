<?php

use App\Enums\ProcurementStatus;
use App\Enums\WaveStatus;
use App\Models\Production\Production;
use App\Models\Production\ProductionItem;
use App\Models\Production\ProductionWave;
use App\Models\Supply\Ingredient;
use App\Models\Supply\Supplier;
use App\Models\Supply\SupplierListing;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

describe('ProductionWave Model', function () {
    it('can be created with factory', function () {
        $wave = ProductionWave::factory()->create();

        expect($wave)
            ->toBeInstanceOf(ProductionWave::class)
            ->and($wave->name)->not->toBeEmpty()
            ->and($wave->slug)->not->toBeEmpty();
    });

    it('has draft status by default', function () {
        $wave = ProductionWave::factory()->create();

        expect($wave->status)->toBe(WaveStatus::Draft);
    });

    it('can be approved', function () {
        $user = User::factory()->create();
        $wave = ProductionWave::factory()->approved()->create([
            'approved_by' => $user->id,
        ]);

        expect($wave->status)->toBe(WaveStatus::Approved)
            ->and($wave->approved_by)->toBe($user->id)
            ->and($wave->approved_at)->not->toBeNull()
            ->and($wave->planned_start_date)->not->toBeNull()
            ->and($wave->planned_end_date)->not->toBeNull();
    });

    it('can be in progress', function () {
        $wave = ProductionWave::factory()->inProgress()->create();

        expect($wave->status)->toBe(WaveStatus::InProgress)
            ->and($wave->started_at)->not->toBeNull();
    });

    it('can be completed', function () {
        $wave = ProductionWave::factory()->completed()->create();

        expect($wave->status)->toBe(WaveStatus::Completed)
            ->and($wave->completed_at)->not->toBeNull();
    });
});

describe('ProductionWave - Relationships', function () {
    it('can have productions', function () {
        $wave = ProductionWave::factory()->create();
        Production::factory()->count(3)->forWave($wave)->create();

        expect($wave->productions)->toHaveCount(3);
    });

    it('productions can be orphan', function () {
        $orphanProduction = Production::factory()->orphan()->create();

        expect($orphanProduction->isOrphan())->toBeTrue()
            ->and($orphanProduction->production_wave_id)->toBeNull();
    });

    it('registers productions relation manager for wave edit page', function () {
        $relations = \App\Filament\Resources\Production\ProductionWaves\ProductionWaveResource::getRelations();

        expect($relations)->toContain(\App\Filament\Resources\Production\ProductionWaves\RelationManagers\ProductionsRelationManager::class);
    });

    it('shows productions relation manager on edit wave page', function () {
        $wave = ProductionWave::factory()->create();
        Production::factory()->forWave($wave)->create();

        Livewire::test(\App\Filament\Resources\Production\ProductionWaves\Pages\EditProductionWave::class, [
            'record' => $wave->id,
        ])->assertSeeLivewire(\App\Filament\Resources\Production\ProductionWaves\RelationManagers\ProductionsRelationManager::class);
    });

    it('lists only productions attached to the current wave in relation manager', function () {
        $wave = ProductionWave::factory()->create();
        $otherWave = ProductionWave::factory()->create();

        $waveProductions = Production::factory()->count(2)->forWave($wave)->create();
        $otherWaveProduction = Production::factory()->forWave($otherWave)->create();

        Livewire::test(\App\Filament\Resources\Production\ProductionWaves\RelationManagers\ProductionsRelationManager::class, [
            'ownerRecord' => $wave,
            'pageClass' => \App\Filament\Resources\Production\ProductionWaves\Pages\EditProductionWave::class,
        ])
            ->assertCanSeeTableRecords($waveProductions)
            ->assertCanNotSeeTableRecords([$otherWaveProduction]);
    });
});

describe('ProductionWave - Status Helpers', function () {
    it('can check status', function () {
        $draftWave = ProductionWave::factory()->draft()->create();
        $approvedWave = ProductionWave::factory()->approved()->create();
        $inProgressWave = ProductionWave::factory()->inProgress()->create();
        $completedWave = ProductionWave::factory()->completed()->create();

        expect($draftWave->isDraft())->toBeTrue()
            ->and($draftWave->isApproved())->toBeFalse()
            ->and($approvedWave->isApproved())->toBeTrue()
            ->and($inProgressWave->isInProgress())->toBeTrue()
            ->and($completedWave->isCompleted())->toBeTrue();
    });
});

describe('ProductionWave - Status Transitions', function () {
    it('can transition from draft to approved', function () {
        $wave = ProductionWave::factory()->draft()->create();
        $user = User::factory()->create();

        $wave->update([
            'status' => WaveStatus::Approved,
            'approved_by' => $user->id,
            'approved_at' => now(),
            'planned_start_date' => now()->addDays(7),
            'planned_end_date' => now()->addDays(14),
        ]);

        expect($wave->fresh()->status)->toBe(WaveStatus::Approved);
    });

    it('can be cancelled', function () {
        $wave = ProductionWave::factory()->approved()->create();

        $wave->update(['status' => WaveStatus::Cancelled]);

        expect($wave->fresh()->status)->toBe(WaveStatus::Cancelled);
    });
});

describe('ProductionWave - Soft Deletes', function () {
    it('can be soft deleted', function () {
        $wave = ProductionWave::factory()->create();

        $wave->delete();

        expect($wave->fresh()->deleted_at)->not->toBeNull();
    });

    it('can be restored', function () {
        $wave = ProductionWave::factory()->create();
        $wave->delete();

        $wave->restore();

        expect($wave->fresh()->deleted_at)->toBeNull();
    });
});

describe('ProductionWaveResource - table actions', function () {
    it('approves draft wave with planned dates set as strings', function () {
        $wave = ProductionWave::factory()->draft()->create([
            'planned_start_date' => now()->addDays(7)->toDateString(),
            'planned_end_date' => now()->addDays(10)->toDateString(),
        ]);

        Livewire::test(\App\Filament\Resources\Production\ProductionWaves\Pages\ListProductionWaves::class)
            ->callAction(TestAction::make('approve')->table($wave))
            ->assertHasNoErrors();

        expect($wave->fresh()->status)->toBe(WaveStatus::Approved);
    });

    it('approves a draft wave from list action', function () {
        $wave = ProductionWave::factory()->draft()->create();

        Livewire::test(\App\Filament\Resources\Production\ProductionWaves\Pages\ListProductionWaves::class)
            ->callAction(TestAction::make('approve')->table($wave))
            ->assertHasNoErrors();

        expect($wave->fresh()->status)->toBe(WaveStatus::Approved);
    });

    it('starts an approved wave from list action', function () {
        $wave = ProductionWave::factory()->approved()->create();

        Livewire::test(\App\Filament\Resources\Production\ProductionWaves\Pages\ListProductionWaves::class)
            ->callAction(TestAction::make('start')->table($wave))
            ->assertHasNoErrors();

        expect($wave->fresh()->status)->toBe(WaveStatus::InProgress);
    });

    it('opens procurement plan action for a wave', function () {
        $wave = ProductionWave::factory()->approved()->create();
        $production = Production::factory()->create(['production_wave_id' => $wave->id]);
        $ingredient = Ingredient::factory()->create();
        $supplier = Supplier::factory()->create();
        $listing = SupplierListing::factory()->create([
            'ingredient_id' => $ingredient->id,
            'supplier_id' => $supplier->id,
        ]);

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'supplier_listing_id' => $listing->id,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        Livewire::test(\App\Filament\Resources\Production\ProductionWaves\Pages\ListProductionWaves::class)
            ->callAction(TestAction::make('procurementPlan')->table($wave))
            ->assertHasNoErrors();
    });

    it('opens procurement plan action even without requirements', function () {
        $wave = ProductionWave::factory()->approved()->create();

        Livewire::test(\App\Filament\Resources\Production\ProductionWaves\Pages\ListProductionWaves::class)
            ->callAction(TestAction::make('procurementPlan')->table($wave))
            ->assertHasNoErrors();
    });

});

describe('Procurement plan print route', function () {
    it('renders printable procurement plan document', function () {
        $wave = ProductionWave::factory()->create();
        $production = Production::factory()->create(['production_wave_id' => $wave->id]);
        $ingredient = Ingredient::factory()->create(['name' => 'Huile de Coco']);

        ProductionItem::factory()->create([
            'production_id' => $production->id,
            'ingredient_id' => $ingredient->id,
            'required_quantity' => 22.5,
            'procurement_status' => ProcurementStatus::NotOrdered,
        ]);

        $response = $this->get(route('production-waves.procurement-plan.print', $wave));

        $response
            ->assertOk()
            ->assertSee('Plan achats - '.$wave->name)
            ->assertSee('Huile de Coco');
    });
});
