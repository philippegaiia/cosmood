<?php

namespace App\Models\Supply;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'slug',
        'address1',
        'address2',
        'is_active',
        'zipcode',
        'city',
        'country',
        'email',
        'phone',
        'website',
        'description',
        'customer_code',
    ];

    public function contacts(): HasMany
    {
        return $this->hasMany(SupplierContact::class, 'supplier_id');
    }

    public function supplier_listings(): HasMany
    {
        return $this->hasMany(SupplierListing::class);
    }
}
