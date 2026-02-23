<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FormulaProductTableSeeder extends Seeder
{
    /**
     * Auto generated seed file
     *
     * @return void
     */
    public function run()
    {

        DB::table('formula_product')->delete();

        DB::table('formula_product')->insert([
            0 => [
                'id' => 1,
                'formula_id' => 1,
                'product_id' => 1,
                'deleted_at' => null,
                'created_at' => null,
                'updated_at' => null,
            ],
            1 => [
                'id' => 2,
                'formula_id' => 1,
                'product_id' => 14,
                'deleted_at' => null,
                'created_at' => null,
                'updated_at' => null,
            ],
            2 => [
                'id' => 3,
                'formula_id' => 2,
                'product_id' => 11,
                'deleted_at' => null,
                'created_at' => null,
                'updated_at' => null,
            ],
            3 => [
                'id' => 4,
                'formula_id' => 2,
                'product_id' => 19,
                'deleted_at' => null,
                'created_at' => null,
                'updated_at' => null,
            ],
        ]);

    }
}
