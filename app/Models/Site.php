<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Site extends Model
{
    protected $fillable = [
        'name',
        'location',
    ];

    public function issuance(): HasMany{
        return $this->hasMany(issuance::class);
    }
}
