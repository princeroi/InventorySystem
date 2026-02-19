<?php

namespace App\Filament\Resources\Issuances;

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
use Illuminate\Support\Facades\Auth;

class IssuanceResource extends Resource
{
    protected static ?string $model = Issuance::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpOnSquare;
    protected static ?string $recordTitleAttribute = 'issued_to';

    // -------------------------------------------------------------------------
    // Permissions
    // -------------------------------------------------------------------------

    private static function userCan(string $permission): bool
    {
        return Auth::user()?->can($permission) ?? false;
    }

    public static function canViewAny(): bool       { return self::userCan('view-any issuance'); }
    public static function canCreate(): bool        { return self::userCan('create issuance'); }
    public static function canEdit($record): bool   { return self::userCan('update issuance'); }
    public static function canDelete($record): bool { return self::userCan('delete issuance'); }

    // -------------------------------------------------------------------------
    // Navigation
    // -------------------------------------------------------------------------

    public static function getNavigationGroup(): ?string
    {
        return 'Issuance / Deliveries';
    }

    // -------------------------------------------------------------------------
    // Schema / Table
    // -------------------------------------------------------------------------

    public static function form(Schema $schema): Schema
    {
        return IssuanceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IssuancesTable::configure($table);
    }

    // -------------------------------------------------------------------------
    // Query
    // -------------------------------------------------------------------------

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'site:id,name',
            'items.item:id,name',
            'logs',
        ]);
    }

    // -------------------------------------------------------------------------
    // Pages / Relations
    // -------------------------------------------------------------------------

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIssuances::route('/'),
        ];
    }
}