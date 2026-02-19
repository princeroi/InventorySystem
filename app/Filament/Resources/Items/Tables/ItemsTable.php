<?php

namespace App\Filament\Resources\Items\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Actions\Action;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Facades\Auth;

class ItemsTable
{
    // -------------------------------------------------------------------------
    // Permission Helper
    // -------------------------------------------------------------------------

    private static function userCan(string $permission): bool
    {
        return Auth::user()?->can($permission) ?? false;
    }

    // -------------------------------------------------------------------------
    // Table Configuration
    // -------------------------------------------------------------------------

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),

                TextColumn::make('category.name')
                    ->searchable(),

                TextColumn::make('description')
                    ->limit(40)
                    ->placeholder('No Description'),

                // Uses the already eager-loaded itemVariants collection —
                // no extra query fired per row.
                TextColumn::make('total_qty')
                    ->label('Total Qty')
                    ->getStateUsing(
                        fn ($record) => $record->itemVariants->sum('quantity')
                    ),
            ])
            ->filters([])
            ->recordActions([
                Action::make('viewVariants')
                    ->label('Variants')
                    ->icon('heroicon-o-eye')
                    ->visible(fn () => self::userCan('view-any item'))
                    ->modalHeading(fn ($record) => "Variants — {$record->name}")
                    ->modalContent(fn ($record) => new HtmlString(
                        $record->itemVariants->isEmpty()
                            ? '<p style="text-align:center;padding:24px;color:#9ca3af;">No variants found.</p>'
                            : '<table style="width:100%;border-collapse:collapse;font-size:14px;">
                                    <thead>
                                        <tr style="border-bottom:2px solid #374151;">
                                            <th style="padding:10px 12px;text-align:left;color:#9ca3af;font-weight:600;">Size</th>
                                            <th style="padding:10px 12px;text-align:right;color:#9ca3af;font-weight:600;">Quantity</th>
                                        </tr>
                                    </thead>
                                    <tbody>' .
                                        $record->itemVariants->map(fn ($variant) =>
                                            '<tr style="border-bottom:1px solid #1f2937;">
                                                <td style="padding:10px 12px;color:#f9fafb;">' . e($variant->size_label) . '</td>
                                                <td style="padding:10px 12px;text-align:right;color:#f9fafb;font-weight:600;">' . e($variant->quantity) . '</td>
                                            </tr>'
                                        )->join('') .
                                    '</tbody>
                                    <tfoot>
                                        <tr style="border-top:2px solid #374151;">
                                            <td style="padding:10px 12px;color:#9ca3af;font-weight:600;">Total</td>
                                            <td style="padding:10px 12px;text-align:right;color:#f9fafb;font-weight:700;">' . $record->itemVariants->sum('quantity') . '</td>
                                        </tr>
                                    </tfoot>
                            </table>'
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),

                EditAction::make()
                    ->visible(fn () => self::userCan('update item')),

                DeleteAction::make()
                    ->visible(fn () => self::userCan('delete item')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => self::userCan('delete item')),
                ]),
            ]);
    }
}