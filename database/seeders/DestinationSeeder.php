<?php

namespace Database\Seeders;

use App\Models\Production\Destination;
use Illuminate\Database\Seeder;

class DestinationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $destinations = [];

        foreach ($destinations as $destination) {
            Destination::query()->updateOrCreate(
                ['slug' => $destination['slug']],
                $destination,
            );
        }
    }
}
