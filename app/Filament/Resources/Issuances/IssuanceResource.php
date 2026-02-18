<?php

namespace App\Filament\Resources\Issuances;

use App\Filament\Resources\Issuances\Pages\CreateIssuance;
use App\Filament\Resources\Issuances\Pages\EditIssuance;
use App\Filament\Resources\Issuances\Pages\ListIssuances;
use App\Filament\Resources\Issuances\Schemas\IssuanceForm;
use App\Filament\Resources\Issuances\Tables\IssuancesTable;
use App\Models\Issuance;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class IssuanceResource extends Resource
{
    protected static ?string $model = Issuance::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'Issuance';

    public static function getNavigationGroup(): ?string
    {
        return 'Issuance / Deliveries';
    }

    public static function form(Schema $schema): Schema
    {
        return IssuanceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IssuancesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIssuances::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('items.item');
    }

}
