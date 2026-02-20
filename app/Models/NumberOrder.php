<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NumberOrder extends Model
{
    protected $fillable = ['user_id', 'service_id', 'country_id', 'status', 'cost'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }
}
