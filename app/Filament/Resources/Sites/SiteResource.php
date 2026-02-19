<?php

namespace App\Filament\Resources\Sites;

use App\Filament\Resources\Sites\Pages\CreateSite;
use App\Filament\Resources\Sites\Pages\EditSite;
use App\Filament\Resources\Sites\Pages\ListSites;
use App\Filament\Resources\Sites\Schemas\SiteForm;
use App\Filament\Resources\Sites\Tables\SitesTable;
use App\Models\Site;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class SiteResource extends Resource
{
    protected static ?string $model = Site::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    // Removed $recordTitleAttribute = 'Site' — 'Site' is not a model column

    // -------------------------------------------------------------------------
    // Permissions
    // -------------------------------------------------------------------------

    private static function userCan(string $permission): bool
    {
        return Auth::user()?->can($permission) ?? false;
    }

    public static function canViewAny(): bool       { return self::userCan('view-any site'); }
    public static function canCreate(): bool        { return self::userCan('create site'); }
    public static function canEdit($record): bool   { return self::userCan('update site'); }
    public static function canDelete($record): bool { return self::userCan('delete site'); }

    // -------------------------------------------------------------------------
    // Navigation
    // -------------------------------------------------------------------------

    public static function getNavigationGroup(): ?string
    {
        return 'Stock Management';
    }

    // -------------------------------------------------------------------------
    // Schema / Table
    // -------------------------------------------------------------------------

    public static function form(Schema $schema): Schema
    {
        return SiteForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SitesTable::configure($table);
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
            'index' => ListSites::route('/'),
        ];
    }
}