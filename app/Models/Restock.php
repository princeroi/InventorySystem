<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Restock extends Model
{
    protected $fillable = [
        'supplier_name',
        'ordered_by',
        'ordered_at',
        'status',
        'note',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(RestockItem::class);
    }

    public function getItemIdsAttribute(): string
    {
        return $this->items->map(fn ($i) =>
            $i->size
                ? "{$i->item->name} ({$i->size}) x{$i->quantity}"
                : "{$i->item->name} x{$i->quantity}"
        )->implode('<br>');
    }
}
