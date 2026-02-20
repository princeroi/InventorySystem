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
        'released_quantity',
        'remaining_quantity',
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
        // ── created ──────────────────────────────────────────────────────────
        // The CREATE flow in the modal uses IssuanceItem::insert() which
        // bypasses this event entirely. Stock deduction for new issuances is
        // handled in ListIssuances CreateAction::after().
        //
        // This event only fires for items added via the EDIT modal
        // (EditAction::after() uses IssuanceItem::create() individually).
        // In that case we deduct stock if the issuance is in a stock-consuming
        // status.
        static::created(function (self $issuanceItem) {
            $issuance = $issuanceItem->issuance;

            if (! $issuance) return;

            if (in_array($issuance->status, ['issued', 'released'])) {
                self::adjustStock($issuanceItem, 'decrement');
            }
        });

        // ── deleted ──────────────────────────────────────────────────────────
        // When a size row is removed in the Edit modal Repeater, restore stock
        // if the issuance is in a stock-consuming status.
        static::deleted(function (self $issuanceItem) {
            $issuance = $issuanceItem->issuance;

            if (! $issuance) return;

            if (in_array($issuance->status, ['issued', 'released'])) {
                self::adjustStock($issuanceItem, 'increment');
            }
        });

        // ── updated ──────────────────────────────────────────────────────────
        // When quantity changes on an existing item row in the Edit modal,
        // adjust the stock difference.
        static::updated(function (self $issuanceItem) {
            if (! $issuanceItem->isDirty('quantity')) {
                return;
            }

            $issuance = $issuanceItem->issuance;

            if (! $issuance) return;

            if (! in_array($issuance->status, ['issued', 'released'])) {
                return;
            }

            $oldQty = $issuanceItem->getOriginal('quantity');
            $newQty = $issuanceItem->quantity;
            $diff   = $newQty - $oldQty;

            if ($diff === 0) return;

            $variant = ItemVariant::where('item_id', $issuanceItem->item_id)
                ->where('size_label', $issuanceItem->size)
                ->first();

            if (! $variant) return;

            $diff > 0
                ? $variant->decrement('quantity', $diff)
                : $variant->increment('quantity', abs($diff));
        });
    }

    // -------------------------------------------------------------------------
    // Stock Helper
    // -------------------------------------------------------------------------

    private static function adjustStock(self $issuanceItem, string $direction): void
    {
        $variant = ItemVariant::where('item_id', $issuanceItem->item_id)
            ->where('size_label', $issuanceItem->size)
            ->first();

        if (! $variant) return;

        $direction === 'decrement'
            ? $variant->decrement('quantity', $issuanceItem->quantity)
            : $variant->increment('quantity', $issuanceItem->quantity);
    }
}