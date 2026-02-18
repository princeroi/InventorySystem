<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssuanceItem extends Model
{
    protected $fillable = [
        'issuance_id',
        'item_id',
        'size',
        'quantity',
    ];

    public function issuance(): BelongsTo
    {
        return $this->belongsTo(Issuance::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    protected static function booted(): void
    {
        // When an item is first created, deduct stock if the parent issuance
        // is already in a stock-consuming status.
        static::created(function (self $issuanceItem) {
            $status = $issuanceItem->issuance?->status;

            if (in_array($status, ['issued', 'released'])) {
                self::adjustStock($issuanceItem, 'decrement');
            }

        });

        // When an item row is deleted (e.g. removed from Repeater on edit),
        // reverse its stock effect based on the parent issuance status.
        static::deleted(function (self $issuanceItem) {
            $status = $issuanceItem->issuance?->status;

            if (in_array($status, ['issued', 'released'])) {
                self::adjustStock($issuanceItem, 'increment'); // restore
            }
        });

        // When quantity is updated on an existing item row, adjust the diff.
        static::updated(function (self $issuanceItem) {
            if (! $issuanceItem->isDirty('quantity')) {
                return;
            }

            $status = $issuanceItem->issuance?->status;

            if (! in_array($status, ['issued', 'released', 'returned'])) {
                return;
            }

            $oldQty = $issuanceItem->getOriginal('quantity');
            $newQty = $issuanceItem->quantity;
            $diff   = $newQty - $oldQty; // positive = more taken, negative = less taken

            if ($diff === 0) {
                return;
            }

            $variant = ItemVariant::where('item_id', $issuanceItem->item_id)
                ->where('size_label', $issuanceItem->size)
                ->first();

            if (! $variant) {
                return;
            }

            if (in_array($status, ['issued', 'released'])) {
                // More quantity = deduct more; less quantity = restore some
                $diff > 0
                    ? $variant->decrement('quantity', $diff)
                    : $variant->increment('quantity', abs($diff));
            }

        });
    }

    private static function adjustStock(self $issuanceItem, string $direction): void
    {
        $variant = ItemVariant::where('item_id', $issuanceItem->item_id)
            ->where('size_label', $issuanceItem->size)
            ->first();

        if (! $variant) {
            return;
        }

        $direction === 'decrement'
            ? $variant->decrement('quantity', $issuanceItem->quantity)
            : $variant->increment('quantity', $issuanceItem->quantity);
    }
}