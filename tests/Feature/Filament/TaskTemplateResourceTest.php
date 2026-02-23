<?php

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
    it('can create a task template', function () {
        Livewire::test(\App\Filament\Resources\TaskTemplates\Pages\CreateTaskTemplate::class)
            ->fillForm([
                'name' => 'New Template',
                'is_default' => false,
                'items' => [
                    ['name' => 'Task 1', 'duration_minutes' => 60, 'offset_days' => 0, 'skip_weekends' => true],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(TaskTemplate::class, [
            'name' => 'New Template',
        ]);
    });
});

describe('TaskTemplateResource Edit', function () {
    it('can edit a task template', function () {
        $template = TaskTemplate::factory()->create();

        Livewire::test(\App\Filament\Resources\TaskTemplates\Pages\EditTaskTemplate::class, ['record' => $template->id])
            ->fillForm([
                'name' => 'Updated Template',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($template->fresh()->name)->toBe('Updated Template');
    });
});
