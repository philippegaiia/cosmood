<?php

namespace Database\Factories\Production;

use App\Enums\WaveStatus;
use App\Models\Production\ProductionWave;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProductionWaveFactory extends Factory
{
    protected $model = ProductionWave::class;

    public function definition(): array
    {
        $name = 'Wave '.$this->faker->monthName.' '.$this->faker->year.' '.$this->faker->unique()->numerify('###');

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'status' => WaveStatus::Draft,
            'planned_start_date' => null,
            'planned_end_date' => null,
            'approved_by' => null,
            'approved_at' => null,
            'started_at' => null,
            'completed_at' => null,
            'notes' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WaveStatus::Draft,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WaveStatus::Approved,
            'planned_start_date' => now()->addDays(7),
            'planned_end_date' => now()->addDays(14),
            'approved_by' => User::factory(),
            'approved_at' => now(),
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WaveStatus::InProgress,
            'planned_start_date' => now()->subDays(3),
            'planned_end_date' => now()->addDays(4),
            'approved_by' => User::factory(),
            'approved_at' => now()->subDays(5),
            'started_at' => now()->subDays(3),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WaveStatus::Completed,
            'planned_start_date' => now()->subDays(14),
            'planned_end_date' => now()->subDays(7),
            'approved_by' => User::factory(),
            'approved_at' => now()->subDays(16),
            'started_at' => now()->subDays(14),
            'completed_at' => now()->subDays(7),
        ]);
    }
}
