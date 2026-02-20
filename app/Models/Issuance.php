<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\IssuanceLog;

class Issuance extends Model
{
    protected $fillable = [
        'site_id',
        'issued_to',
        'status',
        'pending_at',
        'released_at',
        'issued_at',
        'partial_at',
        'returned_at',
        'cancelled_at',
        'note',
    ];

    protected $casts = [
        'pending_at'   => 'date',
        'released_at'  => 'date',
        'issued_at'    => 'date',
        'partial_at'   => 'date',
        'returned_at'  => 'date',
        'cancelled_at' => 'date',
    ];

    public ?array $logSnapshot = null;
    public bool $forceLog      = false;

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(IssuanceItem::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(IssuanceLog::class)->orderBy('created_at', 'desc');
    }

    // -------------------------------------------------------------------------
    // Accessors
    // -------------------------------------------------------------------------

    public function getItemIdsAttribute(): string
    {
        return $this->items->map(fn ($i) =>
            $i->size
                ? "{$i->item->name} ({$i->size}) - {$i->quantity}"
                : "{$i->item->name} - {$i->quantity}"
        )->implode('<br>');
    }

    public function getStatusDateAttribute(): ?\Illuminate\Support\Carbon
    {
        return match ($this->status) {
            'pending'   => $this->pending_at,
            'released'  => $this->released_at,
            'issued'    => $this->issued_at,
            'partial'   => $this->partial_at,
            'returned'  => $this->returned_at,
            'cancelled' => $this->cancelled_at,
            default     => null,
        };
    }

    // -------------------------------------------------------------------------
    // Stock Helpers
    // -------------------------------------------------------------------------

    /**
     * Deduct stock for all items on this issuance.
     * Used when status changes TO issued/released via the Edit modal.
     */
    public function deductStock(): void
    {
        $this->loadMissing('items');

        foreach ($this->items as $item) {
            $variant = ItemVariant::where('item_id', $item->item_id)
                ->where('size_label', $item->size)
                ->first();

            $variant?->decrement('quantity', $item->quantity);
        }
    }

    /**
     * Restore stock for all items on this issuance.
     * Used when status reverts AWAY from issued/released via the Edit modal.
     */
    public function restoreStock(): void
    {
        $this->loadMissing('items');

        foreach ($this->items as $item) {
            $variant = ItemVariant::where('item_id', $item->item_id)
                ->where('size_label', $item->size)
                ->first();

            $variant?->increment('quantity', $item->quantity);
        }
    }

    // -------------------------------------------------------------------------
    // Model Events
    // -------------------------------------------------------------------------

    protected static function booted(): void
    {
        // ── Saving: auto-fill the status timestamp ───────────────────────────
        static::saving(function (self $issuance) {
            if ($issuance->isDirty('status')) {
                $column = match ($issuance->status) {
                    'pending'   => 'pending_at',
                    'released'  => 'released_at',
                    'issued'    => 'issued_at',
                    'partial'   => 'partial_at',
                    'returned'  => 'returned_at',
                    'cancelled' => 'cancelled_at',
                    default     => null,
                };

                if ($column && empty($issuance->{$column})) {
                    $issuance->{$column} = now();
                }
            }
        });

        // ── Created: log only ────────────────────────────────────────────────
        // Stock deduction for the CREATE flow is intentionally NOT done here.
        // Reason: The create modal uses insert() in ListIssuances::after()
        // which bypasses model events, and items don't exist at this point
        // anyway. Stock deduction on create is handled directly in the
        // ListIssuances CreateAction::after() callback.
        static::created(function (self $issuance) {
            $action = match ($issuance->status) {
                'pending'   => 'pending',
                'released'  => 'released',
                'issued'    => 'issued',
                'partial'   => 'partial',
                'returned'  => 'returned',
                'cancelled' => 'cancelled',
                default     => 'created',
            };

            IssuanceLog::create([
                'issuance_id'  => $issuance->id,
                'action'       => $action,
                'performed_by' => auth()->user()?->name ?? 'System',
                'note'         => null,
            ]);
        });

        // ── Updated: stock changes + activity log ────────────────────────────
        static::updated(function (self $issuance) {
            if ($issuance->wasChanged('status')) {
                $newStatus = $issuance->status;
                $oldStatus = $issuance->getOriginal('status');

                $stockStatuses    = ['issued', 'released'];
                $wasStockConsumed = in_array($oldStatus, $stockStatuses);
                $isStockConsumed  = in_array($newStatus, $stockStatuses);

                // ── INTO stock-consuming status (e.g. pending → released) ────
                // Only fires from the Edit modal status dropdown change.
                // The dedicated Release/Issue action buttons set $logSnapshot
                // and handle their own stock, so we skip when logSnapshot is set
                // to avoid double-deduction.
                if ($isStockConsumed && ! $wasStockConsumed && $issuance->logSnapshot === null) {
                    $issuance->deductStock();
                }

                // ── OUT of stock-consuming status (e.g. released → pending) ──
                // Restore stock when the user reverts via the Edit modal.
                // The Return action uses updateQuietly() so this won't fire
                // for it — no risk of double-restoration.
                if (! $isStockConsumed && $wasStockConsumed && $issuance->logSnapshot === null) {
                    $issuance->restoreStock();
                }
            }

            // ── Activity log ─────────────────────────────────────────────────
            if (! $issuance->wasChanged('status')) return;

            $note = null;
            if (! empty($issuance->logSnapshot)) {
                $note = json_encode($issuance->logSnapshot);
            }

            IssuanceLog::create([
                'issuance_id'  => $issuance->id,
                'action'       => $issuance->status,
                'performed_by' => auth()->user()?->name ?? 'System',
                'note'         => $note,
            ]);

            $issuance->logSnapshot = null;
            $issuance->forceLog    = false;
        });
    }
}