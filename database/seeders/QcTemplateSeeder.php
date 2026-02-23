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
                    'product_type_id' => $soapType->id,
                    'name' => 'QC Soap Bars',
                ],
                [
                    'is_default' => true,
                    'is_active' => true,
                ]
            );

            $soapTemplate->items()->delete();

            $soapTemplate->items()->createMany([
                [
                    'code' => 'WEIGHT_AVG',
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
                    'code' => 'PH_CHECK',
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
                    'code' => 'ODOUR_OK',
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
                    'product_type_id' => $balmType->id,
                    'name' => 'QC Balms',
                ],
                [
                    'is_default' => true,
                    'is_active' => true,
                ]
            );

            $balmTemplate->items()->delete();

            $balmTemplate->items()->createMany([
                [
                    'code' => 'NET_WEIGHT',
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
                    'code' => 'ODOUR_OK',
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
                    'code' => 'TEXTURE',
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
                'product_type_id' => null,
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
                'code' => 'VISUAL',
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
