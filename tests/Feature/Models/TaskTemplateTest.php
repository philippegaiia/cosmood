<?php

use App\Models\Production\ProductCategory;
use App\Models\Production\TaskTemplate;
use App\Models\Production\TaskTemplateItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('TaskTemplate Model', function () {
    it('can be created with factory', function () {
        $template = TaskTemplate::factory()->create();

        expect($template)
            ->toBeInstanceOf(TaskTemplate::class)
            ->and($template->name)->not->toBeEmpty();
    });

    it('belongs to a product category', function () {
        $category = ProductCategory::factory()->create();
        $template = TaskTemplate::factory()->create(['product_category_id' => $category->id]);

        expect($template->productCategory->id)->toBe($category->id);
    });

    it('has many items', function () {
        $template = TaskTemplate::factory()->create();
        TaskTemplateItem::factory()->count(3)->create(['task_template_id' => $template->id]);

        expect($template->items)->toHaveCount(3);
    });

    it('orders items by sort_order', function () {
        $template = TaskTemplate::factory()->create();
        TaskTemplateItem::factory()->create(['task_template_id' => $template->id, 'sort_order' => 3]);
        TaskTemplateItem::factory()->create(['task_template_id' => $template->id, 'sort_order' => 1]);
        TaskTemplateItem::factory()->create(['task_template_id' => $template->id, 'sort_order' => 2]);

        $items = $template->items;

        expect($items->first()->sort_order)->toBe(1);
        expect($items->last()->sort_order)->toBe(3);
    });

    it('can be marked as default', function () {
        $template = TaskTemplate::factory()->create(['is_default' => true]);

        expect($template->is_default)->toBeTrue();
    });
});
