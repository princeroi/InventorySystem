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
        'delivered_quantity',
        'remaining_quantity',
    ];

    public function restock(): BelongsTo
    {
        return $this->belongsTo(Restock::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function getReturnableQuantityAttribute(): int
    {
        return $this->delivered_quantity ?? $this->quantity;
    }

    public function getHasDeliveredAttribute(): bool
    {
        return ($this->delivered_quantity ?? $this->quantity) > 0;
    }
}
