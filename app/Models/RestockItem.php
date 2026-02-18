<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestockItem extends Model
{
    protected $fillable = [
        'restock_id',
        'item_id',
        'size',
        'quantity',
    ];

    public function restock(): BelongsTo
    {
        return $this->belongsTo(Restock::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
