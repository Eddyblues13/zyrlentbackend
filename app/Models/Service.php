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

    /**
     * Generate a slug from the service name (used by ProviderRouter for internal pool matching).
     */
    public function getSlugAttribute(): string
    {
        return \Illuminate\Support\Str::slug($this->name);
    }
}
