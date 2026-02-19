<?php

namespace App\Filament\Resources\Restocks;

use App\Filament\Resources\Restocks\Pages\ListRestocks;
use App\Filament\Resources\Restocks\Schemas\RestockForm;
use App\Filament\Resources\Restocks\Tables\RestocksTable;
use App\Models\Restock;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class RestockResource extends Resource
{
    protected static ?string $model = Restock::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownOnSquare;

    // -------------------------------------------------------------------------
    // Permissions
    // -------------------------------------------------------------------------

    private static function userCan(string $permission): bool
    {
        return Auth::user()?->can($permission) ?? false;
    }

    public static function canViewAny(): bool       { return self::userCan('view-any restock'); }
    public static function canCreate(): bool        { return self::userCan('create restock'); }
    public static function canEdit($record): bool   { return self::userCan('update restock'); }
    public static function canDelete($record): bool { return self::userCan('delete restock'); }

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
        return RestockForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RestocksTable::configure($table);
    }

    // -------------------------------------------------------------------------
    // Query
    // -------------------------------------------------------------------------

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
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
            'index' => ListRestocks::route('/'),
        ];
    }
}