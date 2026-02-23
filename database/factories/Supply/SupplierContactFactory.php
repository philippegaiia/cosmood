<?php

namespace Database\Factories\Supply;

use App\Models\Supply\Supplier;
use App\Models\Supply\SupplierContact;
use Illuminate\Database\Eloquent\Factories\Factory;

class SupplierContactFactory extends Factory
{
    protected $model = SupplierContact::class;

    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'phone' => $this->faker->phoneNumber(),
            'mobile' => $this->faker->phoneNumber(),
            'email' => $this->faker->companyEmail(),
            'department' => $this->faker->randomElement(['Sales', 'Marketing', 'Logistics', 'Finance']),
            'description' => $this->faker->sentence(),
        ];
    }
}
