<?php

namespace Database\Factories\Supply;

use App\Models\Supply\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition(): array
    {
        $name = $this->faker->unique()->company();

        return [
            'name' => $name,
            'code' => strtoupper($this->faker->unique()->bothify('SUP-####')),
            'slug' => Str::slug($name),
            'customer_code' => strtoupper($this->faker->unique()->bothify('CUST-####')),
            'estimated_delivery_days' => 8,
            'address1' => $this->faker->streetAddress(),
            'address2' => null,
            'is_active' => true,
            'zipcode' => $this->faker->postcode(),
            'city' => $this->faker->city(),
            'country' => $this->faker->country(),
            'email' => $this->faker->unique()->companyEmail(),
            'phone' => $this->faker->phoneNumber(),
            'website' => $this->faker->url(),
            'description' => $this->faker->sentence(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
