<?php

namespace Database\Seeders;

use App\Models\Production\Formula;
use App\Models\Production\FormulaProduct;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;

class FormulasTableSeeder extends Seeder
{
    /**
     * Upsert curated formulas and restore their legacy default product link.
     *
     * The seed must remain idempotent and avoid deleting extra product links a
     * user may have added later, while still restoring the historical default
     * link that used to live on formulas.product_id.
     */
    public function run(): void
    {
        $formulas = [
            0 => [
                'id' => 1,
                'name' => 'Savon Très Doux',
                'product_id' => 1,
                'slug' => null,
                'code' => 'GA001',
                'dip_number' => 'GA001',
                'is_active' => 1,
                'date_of_creation' => '2011-02-01',
                'description' => null,
                'deleted_at' => null,
                'created_at' => '2024-02-18 17:34:33',
                'updated_at' => '2024-02-18 17:36:25',
            ],
            1 => [
                'id' => 2,
                'name' => 'Intuitif',
                'product_id' => 19,
                'slug' => null,
                'code' => 'GA011',
                'dip_number' => 'GA011',
                'is_active' => 1,
                'date_of_creation' => '2012-02-01',
                'description' => null,
                'deleted_at' => null,
                'created_at' => '2024-02-18 17:38:24',
                'updated_at' => '2024-02-18 17:41:33',
            ],
        ];

        foreach ($formulas as $formulaData) {
            $productId = filled($formulaData['product_id'] ?? null)
                ? (int) $formulaData['product_id']
                : null;

            $formula = Formula::query()
                ->withTrashed()
                ->updateOrCreate(
                    ['id' => (int) $formulaData['id']],
                    Arr::except($formulaData, ['product_id'])
                );

            if ($productId === null) {
                continue;
            }

            FormulaProduct::query()
                ->withTrashed()
                ->updateOrCreate(
                    [
                        'formula_id' => (int) $formula->getKey(),
                        'product_id' => $productId,
                    ],
                    [
                        'is_default' => true,
                        'deleted_at' => null,
                    ],
                );
        }
    }
}
