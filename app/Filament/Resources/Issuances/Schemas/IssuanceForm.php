<?php

namespace App\Filament\Resources\Issuances\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Repeater;
use \App\Models\ItemVariant;
use Filament\Forms\Components\Placeholder;
use Illuminate\Support\HtmlString;

class IssuanceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('site_id')
                    ->label('Site')
                    ->relationship('site', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),

                TextInput::make('issued_to')
                    ->required(),

                Select::make('status')
                    ->options([
                        'pending'   => 'Pending',
                        'released'  => 'Released',
                        'issued'    => 'Issued',
                        'returned'  => 'Returned',
                    ])
                    ->default('pending')
                    ->live()
                    ->afterStateUpdated(function (callable $set, $state) {
                        $now = now()->toDateString();

                        match ($state) {
                            'pending'   => $set('pending_at', $now),
                            'released'  => $set('released_at', $now),
                            'issued'    => $set('issued_at', $now),
                            'returned'  => $set('returned_at', $now),
                            'cancelled' => $set('cancelled_at', $now),
                            default     => null,
                        };
                    })
                    ->required(),

                // --- Status date fields (each visible only for its relevant status) ---

                DatePicker::make('pending_at')
                    ->label('Pending Date')
                    ->default(now())
                    ->visible(fn (callable $get) => $get('status') === 'pending')
                    ->required(fn (callable $get) => $get('status') === 'pending'),

                DatePicker::make('released_at')
                    ->label('Released Date')
                    ->visible(fn (callable $get) => $get('status') === 'released')
                    ->required(fn (callable $get) => $get('status') === 'released'),

                DatePicker::make('issued_at')
                    ->label('Issued Date')
                    ->visible(fn (callable $get) => $get('status') === 'issued')
                    ->required(fn (callable $get) => $get('status') === 'issued'),

                DatePicker::make('returned_at')
                    ->label('Returned Date')
                    ->visible(fn (callable $get) => $get('status') === 'returned')
                    ->required(fn (callable $get) => $get('status') === 'returned'),

                DatePicker::make('cancelled_at')
                    ->label('Cancelled Date')
                    ->visible(fn (callable $get) => $get('status') === 'cancelled')
                    ->required(fn (callable $get) => $get('status') === 'cancelled'),

                Repeater::make('items')
                    ->relationship()
                    ->columns(3)
                    ->schema([
                        Select::make('item_id')
                            ->relationship('item', 'name')
                            ->searchable()
                            ->preload()
                            ->live()
                            ->required(),

                        Select::make('size')
                            ->label('Size')
                            ->options(function (callable $get) {
                                $itemId = $get('item_id');
                                if (!$itemId) return [];
                                return ItemVariant::where('item_id', $itemId)
                                    ->get()
                                    ->mapWithKeys(fn ($variant) => [
                                        $variant->size_label => "{$variant->size_label} (stock: {$variant->quantity})"
                                    ])
                                    ->toArray();
                            })
                            ->live()
                            ->required(),

                        TextInput::make('quantity')
                            ->numeric()
                            ->minValue(1)
                            ->live(debounce: 500)
                            ->rules(function (callable $get) {
                                $status = $get('../../status');

                                if (!in_array($status, ['issued', 'released'])) {
                                    return [];
                                }

                                $itemId = $get('item_id');
                                $size   = $get('size');

                                if (!$itemId || !$size) {
                                    return [];
                                }

                                $variant = ItemVariant::where('item_id', $itemId)
                                    ->where('size_label', $size)
                                    ->first();

                                $stock = $variant?->quantity ?? 0;

                                return ["max:{$stock}"];
                            })
                            ->validationMessages([
                                'max' => fn (callable $get) => (function () use ($get): string {
                                    $itemId = $get('item_id');
                                    $size   = $get('size');

                                    $variant = ItemVariant::where('item_id', $itemId)
                                        ->where('size_label', $size)
                                        ->first();

                                    $stock = $variant?->quantity ?? 0;

                                    return "Current stock is {$stock}. Please save as pending or decrease the quantity.";
                                })(),
                            ])
                            ->required(),

                        Placeholder::make('stock_warning')
                            ->label('')
                            ->columnSpanFull()
                            ->content(function (callable $get): ?HtmlString {
                                $itemId   = $get('item_id');
                                $size     = $get('size');
                                $quantity = (int) $get('quantity');
                                $status   = $get('../../status');

                                if (!$itemId || !$size || $quantity < 1) {
                                    return null;
                                }

                                $variant = ItemVariant::where('item_id', $itemId)
                                    ->where('size_label', $size)
                                    ->first();

                                $stock = $variant?->quantity ?? 0;

                                if ($quantity <= $stock) {
                                    return null;
                                }

                                $isHard = in_array($status, ['issued', 'released']);
                                $color  = $isHard ? 'danger' : 'warning';

                                $message = $isHard
                                    ? "🚫 Current stock is <strong>{$stock}</strong>. Please save as pending or decrease the quantity."
                                    : "⚠️ Current stock is <strong>{$stock}</strong>. You can save as pending but cannot issue/release until stock is sufficient.";

                                return new HtmlString(
                                    "<div class=\"flex items-center gap-2 text-sm font-medium 
                                        text-{$color}-700 bg-{$color}-50 border border-{$color}-300 
                                        rounded-lg px-3 py-2 mt-1\">
                                        <span>{$message}</span>
                                    </div>"
                                );
                            }),
                    ])
                    ->columns(2)
                    ->required(),
            ]);
    }
}
