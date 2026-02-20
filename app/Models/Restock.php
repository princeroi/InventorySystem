<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\RestockLog;

class Restock extends Model
{

    protected $fillable = [
        'supplier_name',
        'ordered_by',
        'ordered_at',
        'status',
        'note',
        'delivered_at',
        'partial_at',
        'returned_at',
        'cancelled_at',
    ];

    protected $casts = [
        'ordered_at'   => 'date',
        'delivered_at' => 'date',
        'partial_at'   => 'date',
        'returned_at'  => 'date',
        'cancelled_at' => 'date',
    ];

    public ?array $logSnapshot = null;
    public bool $forceLog      = false;

    public function items(): HasMany
    {
        return $this->hasMany(RestockItem::class);
    }


    public function getItemIdsAttribute(): string
    {
        return $this->items->map(function ($i) {
            if (! $i->item) return null;

            $name       = $i->size ? "{$i->item->name} ({$i->size})" : $i->item->name;
            $displayQty = $i->delivered_quantity ?? $i->quantity;

            if ($i->delivered_quantity !== null && $i->delivered_quantity < $i->quantity) {
                return "{$name} <span class='text-warning-600 font-semibold'>{$i->delivered_quantity}/{$i->quantity}</span>";
            }

            return "{$name} x{$displayQty}";
        })
        ->filter()
        ->implode('<br>');
    }

    protected static function booted(): void
    {
        static::saving(function (self $restock) {
            if ($restock->isDirty('status')) {
                $column = match ($restock->status) {
                    'pending'   => 'ordered_at',   // pending uses ordered_at
                    'delivered' => 'delivered_at',
                    'partial'   => 'partial_at',
                    'returned'  => 'returned_at',
                    'cancelled' => 'cancelled_at',
                    default     => null,
                };

                if ($column && empty($restock->{$column})) {
                    $restock->{$column} = now();
                }
            }
        });

        static::created(function (self $restock) {
            $action = match ($restock->status) {
                'pending'   => 'created',     // pending = just created/ordered
                'delivered' => 'delivered',
                'partial'   => 'partial',
                'returned'  => 'returned',
                'cancelled' => 'cancelled',
                default     => 'created',
            };

            RestockLog::create([
                'restock_id'   => $restock->id,
                'action'       => $action,
                'performed_by' => auth()->user()?->name ?? 'System',
                'note'         => null,
            ]);
        });

        static::updated(function (self $restock) {
            if (! $restock->isDirty('status')) return;

            $note = null;

            if (! empty($restock->logSnapshot)) {
                $note = json_encode($restock->logSnapshot);
            }

            RestockLog::create([
                'restock_id'   => $restock->id,
                'action'       => $restock->status,
                'performed_by' => auth()->user()?->name ?? 'System',
                'note'         => $note,
            ]);

            $restock->logSnapshot = null;
            $restock->forceLog    = false;
        });
    }

    public function logs(): HasMany
    {
        return $this->hasMany(RestockLog::class)->orderBy('created_at', 'desc');
    }
}
