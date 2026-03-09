<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    protected $fillable = ['name', 'icon', 'color', 'category', 'cost', 'is_active', 'sort_order'];

    protected $casts = [
        'cost'       => 'decimal:2',
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function orders()
    {
        return $this->hasMany(NumberOrder::class);
    }
}
