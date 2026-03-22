<?php

namespace App\Models\Production;

use Database\Factories\Production\DestinationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Destination extends Model
{
    /** @use HasFactory<DestinationFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function defaultForProductionWaves(): HasMany
    {
        return $this->hasMany(ProductionWave::class, 'default_destination_id');
    }

    public function productions(): HasMany
    {
        return $this->hasMany(Production::class);
    }
}
