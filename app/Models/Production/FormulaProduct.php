<?php

namespace App\Models\Production;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FormulaProduct extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'formula_product';

    protected $guarded = [];
}
