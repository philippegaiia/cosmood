<?php

use App\Models\Production\ProductType;
use App\Models\Production\TaskTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

describe('TaskTemplateResource List', function () {
    it('can list task templates', function () {
        $templates = TaskTemplate::factory()->count(3)->create();

        Livewire::test(\App\Filament\Resources\TaskTemplates\Pages\ListTaskTemplates::class)
            ->assertCanSeeTableRecords($templates);
    });

    it('can search task templates by name', function () {
        $template1 = TaskTemplate::factory()->create(['name' => 'Template Alpha']);
        $template2 = TaskTemplate::factory()->create(['name' => 'Template Beta']);

        Livewire::test(\App\Filament\Resources\TaskTemplates\Pages\ListTaskTemplates::class)
            ->searchTable('Alpha')
            ->assertCanSeeTableRecords([$template1])
            ->assertCanNotSeeTableRecords([$template2]);
    });
});

describe('TaskTemplateResource Create', function () {
    it('can create a task template with product type links', function () {
        $productType = ProductType::factory()->create();

        Livewire::test(\App\Filament\Resources\TaskTemplates\Pages\CreateTaskTemplate::class)
            ->fillForm([
                'name' => 'New Template',
                'product_type_links' => [
                    [
                        'product_type_id' => $productType->id,
                        'is_default' => true,
                    ],
                ],
                'taskTemplateTaskTypes' => [],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(TaskTemplate::class, [
            'name' => 'New Template',
        ]);

        $template = TaskTemplate::query()->where('name', 'New Template')->firstOrFail();

        $this->assertDatabaseHas('product_type_task_template', [
            'task_template_id' => $template->id,
            'product_type_id' => $productType->id,
            'is_default' => true,
        ]);
    });
});

describe('TaskTemplateResource Edit', function () {
    it('can edit a task template without re-saving is_default on product_types', function () {
        $productType = ProductType::factory()->create();
        $template = TaskTemplate::factory()->forProductType($productType, true)->create();

        Livewire::test(\App\Filament\Resources\TaskTemplates\Pages\EditTaskTemplate::class, ['record' => $template->id])
            ->fillForm([
                'name' => 'Updated Template',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($template->fresh()->name)->toBe('Updated Template');

        $this->assertDatabaseHas('product_type_task_template', [
            'task_template_id' => $template->id,
            'product_type_id' => $productType->id,
            'is_default' => true,
        ]);
    });

    it('clears the previous default when another template becomes default for the same product type', function () {
        $productType = ProductType::factory()->create();
        $existingDefault = TaskTemplate::factory()->forProductType($productType, true)->create();
        $newTemplate = TaskTemplate::factory()->forProductType($productType, false)->create();

        Livewire::test(\App\Filament\Resources\TaskTemplates\Pages\EditTaskTemplate::class, ['record' => $newTemplate->id])
            ->fillForm([
                'name' => $newTemplate->name,
                'product_type_links' => [
                    [
                        'product_type_id' => $productType->id,
                        'is_default' => true,
                    ],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('product_type_task_template', [
            'task_template_id' => $newTemplate->id,
            'product_type_id' => $productType->id,
            'is_default' => true,
        ]);
        $this->assertDatabaseHas('product_type_task_template', [
            'task_template_id' => $existingDefault->id,
            'product_type_id' => $productType->id,
            'is_default' => false,
        ]);
    });
});
