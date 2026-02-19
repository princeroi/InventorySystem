<?php

namespace App\Filament\Resources\Issuances\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use App\Models\ItemVariant;
use Filament\Notifications\Notification;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Placeholder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class IssuancesTable
{
    // -------------------------------------------------------------------------
    // Permission Helper
    // -------------------------------------------------------------------------

    private static function userCan(string $permission): bool
    {
        return Auth::user()?->can($permission) ?? false;
    }

    // -------------------------------------------------------------------------
    // Variant Map
    // -------------------------------------------------------------------------

    /**
     * Build a keyed map of "item_id:size_label" => ItemVariant for a set of issuance items.
     * One query instead of one-per-item.
     */
    private static function variantMap(iterable $issuanceItems): array
    {
        $pairs = collect($issuanceItems)->map(fn ($i) => [
            'item_id' => $i->item_id,
            'size'    => $i->size,
        ])->unique()->values();

        if ($pairs->isEmpty()) {
            return [];
        }

        $variants = ItemVariant::query()
            ->where(function ($q) use ($pairs) {
                foreach ($pairs as $p) {
                    $q->orWhere(function ($inner) use ($p) {
                        $inner->where('item_id', $p['item_id'])
                              ->where('size_label', $p['size']);
                    });
                }
            })
            ->get();

        $map = [];
        foreach ($variants as $v) {
            $map["{$v->item_id}:{$v->size_label}"] = $v;
        }

        return $map;
    }

    // -------------------------------------------------------------------------
    // Table Configuration
    // -------------------------------------------------------------------------

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort(function ($query) {
                $query->orderByRaw("
                    COALESCE(
                        CASE status
                            WHEN 'pending'   THEN pending_at
                            WHEN 'released'  THEN released_at
                            WHEN 'issued'    THEN issued_at
                            WHEN 'returned'  THEN returned_at
                            WHEN 'cancelled' THEN cancelled_at
                            ELSE NULL
                        END,
                        DATE(created_at)
                    ) DESC
                ")
                ->orderBy('updated_at', 'desc');
            })

            ->columns([
                TextColumn::make('site.name')
                    ->label('Site')
                    ->sortable(),

                TextColumn::make('issued_to')
                    ->searchable(),

                TextColumn::make('status')
                    ->searchable()
                    ->badge()
                    ->colors([
                        'warning' => 'pending',
                        'info'    => 'released',
                        'success' => 'issued',
                        'danger'  => 'cancelled',
                    ]),

                TextColumn::make('status_date')
                    ->label('Date')
                    ->getStateUsing(fn ($record) => match ($record->status) {
                        'pending'   => $record->pending_at,
                        'released'  => $record->released_at,
                        'issued'    => $record->issued_at,
                        'returned'  => $record->returned_at,
                        'cancelled' => $record->cancelled_at,
                        default     => null,
                    })
                    ->formatStateUsing(fn ($state) => $state
                        ? \Carbon\Carbon::parse($state)->format('M d, Y')
                        : '—'
                    )
                    ->sortable(),

                TextColumn::make('item_ids')
                    ->label('Items')
                    ->html(),

                TextColumn::make('note')
                    ->label('Note')
                    ->formatStateUsing(fn ($state) => $state ?: 'No Note')
                    ->limit(50),
            ])
            ->filters([])
            ->recordActions([
                ActionGroup::make([

                    // RELEASE — pending → released, deducts stock
                    Action::make('release')
                        ->label('Release')
                        ->color('primary')
                        ->icon('heroicon-s-truck')
                        ->visible(fn ($record) =>
                            $record->status === 'pending' &&
                            self::userCan('release issuance')
                        )
                        ->action(function ($record) {
                            $variantMap        = self::variantMap($record->items);
                            $insufficientItems = [];

                            foreach ($record->items as $issuanceItem) {
                                $key     = "{$issuanceItem->item_id}:{$issuanceItem->size}";
                                $variant = $variantMap[$key] ?? null;
                                $stock   = $variant?->quantity ?? 0;

                                if ($issuanceItem->quantity > $stock) {
                                    $insufficientItems[] = "{$issuanceItem->item->name} ({$issuanceItem->size}): needs {$issuanceItem->quantity}, has {$stock}";
                                }
                            }

                            if (! empty($insufficientItems)) {
                                Notification::make()
                                    ->title('Insufficient Stock')
                                    ->body('Cannot release. Stock too low for: ' . implode(' | ', $insufficientItems))
                                    ->danger()
                                    ->persistent()
                                    ->send();

                                return;
                            }

                            foreach ($record->items as $issuanceItem) {
                                $key     = "{$issuanceItem->item_id}:{$issuanceItem->size}";
                                $variant = $variantMap[$key] ?? null;

                                if ($variant) {
                                    $variant->decrement('quantity', $issuanceItem->quantity);
                                }
                            }

                            $record->update([
                                'status'      => 'released',
                                'released_at' => now(),
                            ]);

                            Notification::make()
                                ->title('Issuance released and stock deducted.')
                                ->success()
                                ->send();
                        })
                        ->requiresConfirmation(),

                    // ISSUE — released → issued, no stock change
                    Action::make('issue')
                        ->label('Issue')
                        ->color('success')
                        ->icon('heroicon-s-check')
                        ->visible(fn ($record) =>
                            $record->status === 'released' &&
                            self::userCan('issue issuance')
                        )
                        ->action(function ($record) {
                            $record->update([
                                'status'    => 'issued',
                                'issued_at' => now(),
                            ]);

                            Notification::make()
                                ->title('Issuance marked as issued.')
                                ->success()
                                ->send();
                        })
                        ->requiresConfirmation(),

                    // RETURN — released → returned
                    Action::make('return')
                        ->label('Return')
                        ->color('warning')
                        ->icon('heroicon-s-arrow-path')
                        ->visible(fn ($record) =>
                            $record->status === 'released' &&
                            self::userCan('return issuance')
                        )
                        ->modalHeading('Return Issuance')
                        ->modalDescription('Choose whether to restore stock, then adjust quantities per item if needed.')
                        ->modalSubmitActionLabel('Confirm Return')
                        ->form(function ($record): array {
                            $fields = [];

                            $fields[] = Toggle::make('restore_stock')
                                ->label('Restore items back to stock?')
                                ->default(true)
                                ->live()
                                ->helperText('Turn on to add quantities back to inventory.')
                                ->columnSpanFull();

                            foreach ($record->items as $index => $issuanceItem) {
                                $itemName = $issuanceItem->item?->name ?? "Item #{$issuanceItem->item_id}";
                                $size     = $issuanceItem->size;
                                $label    = $size ? "{$itemName} ({$size})" : $itemName;

                                $fields[] = TextInput::make("quantities.{$index}")
                                    ->label($label)
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue($issuanceItem->quantity)
                                    ->default($issuanceItem->quantity)
                                    ->suffix("/ {$issuanceItem->quantity}")
                                    ->helperText("Max returnable: {$issuanceItem->quantity}")
                                    ->visible(fn (callable $get) => (bool) $get('restore_stock'))
                                    ->dehydrated(true);
                            }

                            return $fields;
                        })
                        ->action(function ($record, array $data) {
                            $record->updateQuietly([
                                'status'      => 'returned',
                                'returned_at' => now(),
                            ]);

                            if (! ($data['restore_stock'] ?? true)) {
                                Notification::make()
                                    ->title('Issuance returned without restoring stock.')
                                    ->warning()
                                    ->send();
                                return;
                            }

                            $record->load('items');
                            $quantities  = $data['quantities'] ?? [];
                            $variantMap  = self::variantMap($record->items);

                            foreach ($record->items as $index => $issuanceItem) {
                                $quantityToReturn = (int) ($quantities[$index] ?? $issuanceItem->quantity);

                                if ($quantityToReturn <= 0) {
                                    continue;
                                }

                                $key     = "{$issuanceItem->item_id}:{$issuanceItem->size}";
                                $variant = $variantMap[$key] ?? null;

                                if ($variant) {
                                    $variant->increment('quantity', $quantityToReturn);
                                }
                            }

                            Notification::make()
                                ->title('Issuance returned and stock restored.')
                                ->success()
                                ->send();
                        }),

                    // CANCEL — pending only, no stock change
                    Action::make('cancel')
                        ->label('Cancel')
                        ->color('danger')
                        ->icon('heroicon-s-x-mark')
                        ->visible(fn ($record) =>
                            $record->status === 'pending' &&
                            self::userCan('cancel issuance')
                        )
                        ->action(fn ($record) => $record->update([
                            'status'       => 'cancelled',
                            'cancelled_at' => now(),
                        ]))
                        ->requiresConfirmation(),

                    EditAction::make()
                        ->visible(fn ($record) =>
                            $record->status === 'pending' &&
                            self::userCan('update issuance')
                        )
                        ->fillForm(function ($record): array {
                            $grouped = [];

                            foreach ($record->items as $issuanceItem) {
                                $itemId = $issuanceItem->item_id;

                                if (! isset($grouped[$itemId])) {
                                    $grouped[$itemId] = ['item_id' => $itemId, 'sizes' => []];
                                }

                                $grouped[$itemId]['sizes'][] = [
                                    'size'     => $issuanceItem->size,
                                    'quantity' => $issuanceItem->quantity,
                                ];
                            }

                            return array_merge($record->toArray(), [
                                'issuance_items' => array_values($grouped),
                            ]);
                        })
                        ->mutateFormDataUsing(function (array $data) use (&$cachedItems): array {
                            $cachedItems = $data['issuance_items'] ?? [];
                            unset($data['issuance_items']);
                            return $data;
                        })
                        ->after(function ($record) use (&$cachedItems): void {
                            $record->items()->delete();

                            $rows = [];
                            $now  = now();

                            foreach ($cachedItems as $itemRow) {
                                $itemId = $itemRow['item_id'] ?? null;
                                if (! $itemId) continue;

                                foreach ($itemRow['sizes'] ?? [] as $sizeRow) {
                                    $size     = $sizeRow['size'] ?? null;
                                    $quantity = $sizeRow['quantity'] ?? null;

                                    if (! $size || ! $quantity) continue;

                                    $rows[] = [
                                        'issuance_id' => $record->id,
                                        'item_id'     => $itemId,
                                        'size'        => $size,
                                        'quantity'    => $quantity,
                                        'created_at'  => $now,
                                        'updated_at'  => $now,
                                    ];
                                }
                            }

                            if (! empty($rows)) {
                                \App\Models\IssuanceItem::insert($rows);
                            }
                        }),
                ]),

                Action::make('view_logs')
                    ->label('View Logs')
                    ->icon('heroicon-s-clock')
                    ->color('gray')
                    ->visible(fn () => self::userCan('view-any issuance'))
                    ->modalHeading('Issuance Activity Log')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalWidth('lg')
                    ->form(function ($record): array {
                        $logs = $record->logs;

                        if ($logs->isEmpty()) {
                            return [
                                Placeholder::make('no_logs')
                                    ->label('')
                                    ->content(new HtmlString("
                                        <div style='display:flex; flex-direction:column; align-items:center; justify-content:center; padding:32px 0; color:#9ca3af;'>
                                            <svg style='width:40px;height:40px;margin-bottom:8px;' fill='none' stroke='currentColor' viewBox='0 0 24 24'>
                                                <path stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'/>
                                            </svg>
                                            <span style='font-size:13px;'>No activity logs found.</span>
                                        </div>
                                    ")),
                            ];
                        }

                        $config = [
                            'created'   => ['color' => '#6366f1', 'bg' => '#eef2ff', 'border' => '#c7d2fe', 'label' => 'Created',   'icon' => 'M12 4v16m8-8H4'],
                            'pending'   => ['color' => '#d97706', 'bg' => '#fffbeb', 'border' => '#fde68a', 'label' => 'Pending',   'icon' => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
                            'released'  => ['color' => '#2563eb', 'bg' => '#eff6ff', 'border' => '#bfdbfe', 'label' => 'Released',  'icon' => 'M5 13l4 4L19 7'],
                            'issued'    => ['color' => '#059669', 'bg' => '#ecfdf5', 'border' => '#a7f3d0', 'label' => 'Issued',    'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
                            'returned'  => ['color' => '#ea580c', 'bg' => '#fff7ed', 'border' => '#fed7aa', 'label' => 'Returned',  'icon' => 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15'],
                            'cancelled' => ['color' => '#dc2626', 'bg' => '#fef2f2', 'border' => '#fecaca', 'label' => 'Cancelled', 'icon' => 'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z'],
                        ];

                        $logCount = $logs->count();
                        $fields   = [];

                        foreach ($logs as $i => $log) {
                            $c      = $config[$log->action] ?? $config['created'];
                            $date   = \Carbon\Carbon::parse($log->created_at)->format('M d, Y');
                            $time   = \Carbon\Carbon::parse($log->created_at)->format('h:i A');
                            $isLast = $i === $logCount - 1;
                            $line   = ! $isLast ? "<div style='width:2px; flex:1; background:#e5e7eb; margin-top:4px; min-height:20px;'></div>" : '';
                            $note   = $log->note ? "<div style='font-size:12px; color:#6b7280; margin-top:6px; padding-top:6px; border-top:1px solid {$c['border']};'>{$log->note}</div>" : '';
                            $pb     = $isLast ? '0' : '16px';

                            $fields[] = Placeholder::make("log_{$log->id}")
                                ->label('')
                                ->content(new HtmlString("
                                    <div style='display:flex; gap:16px;'>
                                        <div style='display:flex; flex-direction:column; align-items:center;'>
                                            <div style='width:36px; height:36px; background:{$c['bg']}; border:2px solid {$c['border']}; border-radius:50%; display:flex; align-items:center; justify-content:center; flex-shrink:0;'>
                                                <svg style='width:16px; height:16px; color:{$c['color']};' fill='none' stroke='{$c['color']}' viewBox='0 0 24 24'>
                                                    <path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='{$c['icon']}'/>
                                                </svg>
                                            </div>
                                            {$line}
                                        </div>
                                        <div style='flex:1; padding-bottom:{$pb};'>
                                            <div style='background:{$c['bg']}; border:1px solid {$c['border']}; border-radius:8px; padding:12px 14px;'>
                                                <div style='display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;'>
                                                    <span style='font-size:13px; font-weight:600; color:{$c['color']};'>{$c['label']}</span>
                                                    <span style='font-size:11px; color:#9ca3af;'>{$date} · {$time}</span>
                                                </div>
                                                <div style='font-size:12px; color:#6b7280;'>
                                                    By: <strong style='color:#374151;'>{$log->performed_by}</strong>
                                                </div>
                                                {$note}
                                            </div>
                                        </div>
                                    </div>
                                "));
                        }

                        return $fields;
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([

                    // BULK RELEASE
                    BulkAction::make('bulk_release')
                        ->label('Release Selected')
                        ->color('primary')
                        ->icon('heroicon-s-truck')
                        ->visible(fn () => self::userCan('release issuance'))
                        ->modalDescription(fn ($records) =>
                            'Only pending records will be released. ' .
                            $records->where('status', '!=', 'pending')->count() . ' record(s) will be skipped.'
                        )
                        ->action(function ($records) {
                            $pendingRecords = $records->where('status', 'pending');

                            $allItems   = $pendingRecords->flatMap(fn ($r) => $r->items);
                            $variantMap = self::variantMap($allItems);

                            $skipped  = [];
                            $released = 0;

                            foreach ($pendingRecords as $record) {
                                $insufficientItems = [];

                                foreach ($record->items as $issuanceItem) {
                                    $key     = "{$issuanceItem->item_id}:{$issuanceItem->size}";
                                    $variant = $variantMap[$key] ?? null;
                                    $stock   = $variant?->quantity ?? 0;

                                    if ($issuanceItem->quantity > $stock) {
                                        $insufficientItems[] = "{$issuanceItem->item->name} ({$issuanceItem->size}): needs {$issuanceItem->quantity}, has {$stock}";
                                    }
                                }

                                if (! empty($insufficientItems)) {
                                    $skipped[] = "Issuance #{$record->id}: " . implode(', ', $insufficientItems);
                                    continue;
                                }

                                foreach ($record->items as $issuanceItem) {
                                    $key     = "{$issuanceItem->item_id}:{$issuanceItem->size}";
                                    $variant = $variantMap[$key] ?? null;

                                    if ($variant) {
                                        $variant->decrement('quantity', $issuanceItem->quantity);
                                        // Keep the in-memory map in sync so subsequent
                                        // records see the updated stock level.
                                        $variant->quantity -= $issuanceItem->quantity;
                                    }
                                }

                                $record->update([
                                    'status'      => 'released',
                                    'released_at' => now(),
                                ]);

                                $released++;
                            }

                            if ($released > 0) {
                                Notification::make()
                                    ->title("{$released} record(s) released and stock deducted.")
                                    ->success()
                                    ->send();
                            }

                            if (! empty($skipped)) {
                                Notification::make()
                                    ->title('Some records skipped due to insufficient stock')
                                    ->body(implode(' | ', $skipped))
                                    ->danger()
                                    ->persistent()
                                    ->send();
                            }
                        })
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion(),

                    // BULK ISSUE
                    BulkAction::make('bulk_issue')
                        ->label('Issue Selected')
                        ->color('success')
                        ->icon('heroicon-s-check')
                        ->visible(fn () => self::userCan('issue issuance'))
                        ->modalDescription(fn ($records) =>
                            'Only released records will be issued. ' .
                            $records->where('status', '!=', 'released')->count() . ' record(s) will be skipped.'
                        )
                        ->action(function ($records) {
                            $issued = 0;

                            foreach ($records->where('status', 'released') as $record) {
                                $record->update([
                                    'status'    => 'issued',
                                    'issued_at' => now(),
                                ]);
                                $issued++;
                            }

                            Notification::make()
                                ->title("{$issued} record(s) marked as issued.")
                                ->success()
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion(),

                    // BULK RETURN
                    BulkAction::make('bulk_return')
                        ->label('Return Selected')
                        ->color('warning')
                        ->icon('heroicon-s-arrow-path')
                        ->visible(fn () => self::userCan('return issuance'))
                        ->modalHeading('Bulk Return Issuances')
                        ->modalSubmitActionLabel('Confirm Return')
                        ->form(function (): array {
                            return [
                                Placeholder::make('skip_notice')
                                    ->label('')
                                    ->columnSpanFull()
                                    ->content(new HtmlString(
                                        "<div class='flex items-center gap-2 text-sm text-blue-700 bg-blue-50 border border-blue-200 rounded-lg px-4 py-2'>
                                            ℹ️ Only <strong>released</strong> records will be returned. All others will be skipped.
                                        </div>"
                                    )),

                                Toggle::make('restore_stock')
                                    ->label('Restore items back to stock?')
                                    ->default(true)
                                    ->live()
                                    ->helperText('Turn on to add the full quantities back to inventory for all selected records.')
                                    ->columnSpanFull(),

                                Placeholder::make('restore_notice')
                                    ->label('')
                                    ->columnSpanFull()
                                    ->visible(fn (callable $get) => (bool) $get('restore_stock'))
                                    ->content(new HtmlString(
                                        "<div class='flex items-center gap-2 text-sm text-warning-700 bg-warning-50 border border-warning-200 rounded-lg px-4 py-2'>
                                            ⚠️ The <strong>full issued quantity</strong> of each item will be restored to stock across all selected records.
                                        </div>"
                                    )),

                                Placeholder::make('no_restore_notice')
                                    ->label('')
                                    ->columnSpanFull()
                                    ->visible(fn (callable $get) => ! (bool) $get('restore_stock'))
                                    ->content(new HtmlString(
                                        "<div class='flex items-center gap-2 text-sm text-gray-600 bg-gray-50 border border-gray-200 rounded-lg px-4 py-2'>
                                            🚫 Stock will <strong>not</strong> be restored. Records will be marked as returned only.
                                        </div>"
                                    )),
                            ];
                        })
                        ->action(function ($records, array $data) {
                            $releasedRecords = $records->where('status', 'released');
                            $restoreStock    = (bool) ($data['restore_stock'] ?? true);
                            $returned        = 0;

                            if ($restoreStock) {
                                $allItems   = $releasedRecords->flatMap(fn ($r) => $r->load('items')->items);
                                $variantMap = self::variantMap($allItems);

                                foreach ($releasedRecords as $record) {
                                    $record->updateQuietly([
                                        'status'      => 'returned',
                                        'returned_at' => now(),
                                    ]);

                                    foreach ($record->items as $issuanceItem) {
                                        $key     = "{$issuanceItem->item_id}:{$issuanceItem->size}";
                                        $variant = $variantMap[$key] ?? null;

                                        if ($variant) {
                                            $variant->increment('quantity', $issuanceItem->quantity);
                                        }
                                    }

                                    $returned++;
                                }
                            } else {
                                foreach ($releasedRecords as $record) {
                                    $record->updateQuietly([
                                        'status'      => 'returned',
                                        'returned_at' => now(),
                                    ]);
                                    $returned++;
                                }
                            }

                            $stockMsg = $restoreStock ? 'Stock restored.' : 'Stock not restored.';

                            Notification::make()
                                ->title("{$returned} record(s) returned. {$stockMsg}")
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    // BULK CANCEL
                    BulkAction::make('bulk_cancel')
                        ->label('Cancel Selected')
                        ->color('danger')
                        ->icon('heroicon-s-x-mark')
                        ->visible(fn () => self::userCan('cancel issuance'))
                        ->modalDescription(fn ($records) =>
                            'Only pending records will be cancelled. ' .
                            $records->where('status', '!=', 'pending')->count() . ' record(s) will be skipped.'
                        )
                        ->action(fn ($records) => $records
                            ->where('status', 'pending')
                            ->each->update([
                                'status'       => 'cancelled',
                                'cancelled_at' => now(),
                            ])
                        )
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }
}