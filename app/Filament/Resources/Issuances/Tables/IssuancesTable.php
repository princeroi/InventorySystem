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

class IssuancesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
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
            ])
            ->filters([])
            ->recordActions([
                ActionGroup::make([

                    // RELEASE — pending → released, deducts stock
                    Action::make('release')
                        ->label('Release')
                        ->color('primary')
                        ->icon('heroicon-s-truck')
                        ->visible(fn ($record) => $record->status === 'pending')
                        ->action(function ($record) {
                            $insufficientItems = [];

                            foreach ($record->items as $issuanceItem) {
                                $variant = ItemVariant::where('item_id', $issuanceItem->item_id)
                                    ->where('size_label', $issuanceItem->size)
                                    ->first();

                                $stock = $variant?->quantity ?? 0;

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
                                $variant = ItemVariant::where('item_id', $issuanceItem->item_id)
                                    ->where('size_label', $issuanceItem->size)
                                    ->first();

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
                        ->visible(fn ($record) => $record->status === 'released')
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
                        ->visible(fn ($record) => $record->status === 'released')
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
                                    ->dehydrated(true); // always submit even when hidden
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
                            $quantities = $data['quantities'] ?? [];

                            foreach ($record->items as $index => $issuanceItem) {
                                $quantityToReturn = (int) ($quantities[$index] ?? $issuanceItem->quantity);

                                if ($quantityToReturn <= 0) {
                                    continue;
                                }

                                $variant = ItemVariant::where('item_id', $issuanceItem->item_id)
                                    ->where('size_label', $issuanceItem->size)
                                    ->first();

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
                        ->visible(fn ($record) => $record->status === 'pending')
                        ->action(fn ($record) => $record->update([
                            'status'       => 'cancelled',
                            'cancelled_at' => now(),
                        ]))
                        ->requiresConfirmation(),

                    EditAction::make()
                        ->visible(fn ($record) => $record->status === 'pending'),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([

                    // BULK RELEASE
                    BulkAction::make('bulk_release')
                        ->label('Release Selected')
                        ->color('primary')
                        ->icon('heroicon-s-truck')
                        ->modalDescription(fn ($records) =>
                            'Only pending records will be released. ' .
                            $records->where('status', '!=', 'pending')->count() . ' record(s) will be skipped.'
                        )
                        ->action(function ($records) {
                            $skipped  = [];
                            $released = 0;

                            foreach ($records->where('status', 'pending') as $record) {
                                $insufficientItems = [];

                                foreach ($record->items as $issuanceItem) {
                                    $variant = ItemVariant::where('item_id', $issuanceItem->item_id)
                                        ->where('size_label', $issuanceItem->size)
                                        ->first();

                                    $stock = $variant?->quantity ?? 0;

                                    if ($issuanceItem->quantity > $stock) {
                                        $insufficientItems[] = "{$issuanceItem->item->name} ({$issuanceItem->size}): needs {$issuanceItem->quantity}, has {$stock}";
                                    }
                                }

                                if (! empty($insufficientItems)) {
                                    $skipped[] = "Issuance #{$record->id}: " . implode(', ', $insufficientItems);
                                    continue;
                                }

                                foreach ($record->items as $issuanceItem) {
                                    $variant = ItemVariant::where('item_id', $issuanceItem->item_id)
                                        ->where('size_label', $issuanceItem->size)
                                        ->first();

                                    if ($variant) {
                                        $variant->decrement('quantity', $issuanceItem->quantity);
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

                    BulkAction::make('bulk_return')
                        ->label('Return Selected')
                        ->color('warning')
                        ->icon('heroicon-s-arrow-path')
                        ->modalHeading('Bulk Return Issuances')
                        ->modalSubmitActionLabel('Confirm Return')
                        ->form(function (): array {  // <-- removed `use (&$records)`
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
                            $returned      = 0;
                            $restoreStock  = (bool) ($data['restore_stock'] ?? true);

                            foreach ($records->where('status', 'released') as $record) {
                                $record->updateQuietly([
                                    'status'      => 'returned',
                                    'returned_at' => now(),
                                ]);

                                if ($restoreStock) {
                                    // Fresh load to avoid stale relationship cache
                                    foreach ($record->load('items')->items as $issuanceItem) {
                                        $variant = ItemVariant::where('item_id', $issuanceItem->item_id)
                                            ->where('size_label', $issuanceItem->size)
                                            ->first();

                                        if ($variant) {
                                            $variant->increment('quantity', $issuanceItem->quantity);
                                        }
                                    }
                                }

                                $returned++;
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