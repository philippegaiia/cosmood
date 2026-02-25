<?php

namespace Database\Seeders;

use App\Enums\QcInputType;
use App\Models\Production\ProductType;
use App\Models\Production\QcTemplate;
use Illuminate\Database\Seeder;

class QcTemplateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $soapType = ProductType::query()
            ->where('slug', 'soap-bars')
            ->orWhere('name', 'like', '%soap%')
            ->orWhere('name', 'like', '%savon%')
            ->first();

        $balmType = ProductType::query()
            ->where('slug', 'balms')
            ->orWhere('name', 'like', '%balm%')
            ->orWhere('name', 'like', '%baume%')
            ->first();

        if ($soapType) {
            $soapTemplate = QcTemplate::query()->updateOrCreate(
                [
                    'name' => 'QC Soap Bars',
                ],
                [
                    'is_default' => false,
                    'is_active' => true,
                ]
            );

            $soapType->update([
                'qc_template_id' => $soapTemplate->id,
            ]);

            $soapTemplate->items()->delete();

            $soapTemplate->items()->createMany([
                [
                    'label' => 'Poids unitaire moyen',
                    'input_type' => QcInputType::Number,
                    'unit' => 'g',
                    'min_value' => 95,
                    'max_value' => 105,
                    'target_value' => null,
                    'options' => null,
                    'stage' => 'final_release',
                    'required' => true,
                    'sort_order' => 1,
                ],
                [
                    'label' => 'pH à 10%',
                    'input_type' => QcInputType::Number,
                    'unit' => 'pH',
                    'min_value' => 8.5,
                    'max_value' => 10.5,
                    'target_value' => null,
                    'options' => null,
                    'stage' => 'final_release',
                    'required' => true,
                    'sort_order' => 2,
                ],
                [
                    'label' => 'Odeur conforme',
                    'input_type' => QcInputType::Boolean,
                    'unit' => null,
                    'min_value' => null,
                    'max_value' => null,
                    'target_value' => 'true',
                    'options' => null,
                    'stage' => 'final_release',
                    'required' => true,
                    'sort_order' => 3,
                ],
            ]);
        }

        if ($balmType) {
            $balmTemplate = QcTemplate::query()->updateOrCreate(
                [
                    'name' => 'QC Balms',
                ],
                [
                    'is_default' => false,
                    'is_active' => true,
                ]
            );

            $balmType->update([
                'qc_template_id' => $balmTemplate->id,
            ]);

            $balmTemplate->items()->delete();

            $balmTemplate->items()->createMany([
                [
                    'label' => 'Poids net',
                    'input_type' => QcInputType::Number,
                    'unit' => 'g',
                    'min_value' => 28,
                    'max_value' => 32,
                    'target_value' => null,
                    'options' => null,
                    'stage' => 'final_release',
                    'required' => true,
                    'sort_order' => 1,
                ],
                [
                    'label' => 'Odeur conforme',
                    'input_type' => QcInputType::Boolean,
                    'unit' => null,
                    'min_value' => null,
                    'max_value' => null,
                    'target_value' => 'true',
                    'options' => null,
                    'stage' => 'final_release',
                    'required' => true,
                    'sort_order' => 2,
                ],
                [
                    'label' => 'Texture homogène',
                    'input_type' => QcInputType::Text,
                    'unit' => null,
                    'min_value' => null,
                    'max_value' => null,
                    'target_value' => null,
                    'options' => null,
                    'stage' => 'in_process',
                    'required' => true,
                    'sort_order' => 3,
                ],
            ]);
        }

        $globalTemplate = QcTemplate::query()->updateOrCreate(
            [
                'name' => 'QC Global Standard',
            ],
            [
                'is_default' => true,
                'is_active' => true,
            ]
        );

        $globalTemplate->items()->delete();

        $globalTemplate->items()->createMany([
            [
                'label' => 'Aspect visuel conforme',
                'input_type' => QcInputType::Boolean,
                'unit' => null,
                'min_value' => null,
                'max_value' => null,
                'target_value' => 'true',
                'options' => null,
                'stage' => 'final_release',
                'required' => true,
                'sort_order' => 1,
            ],
        ]);
    }
}
