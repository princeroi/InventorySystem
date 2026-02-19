<?php

namespace App\Filament\Resources\Permissions;

use Althinect\FilamentSpatieRolesPermissions\Resources\PermissionResource as BasePermissionResource;
use App\Filament\Resources\Permissions\Pages\ManagePermissions;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use BackedEnum;

class PermissionResource extends BasePermissionResource
{
    protected static ?string $navigationLabel               = 'Permissions';
    protected static ?int $navigationSort                   = 2;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    public static function getNavigationGroup(): ?string
    {
        return 'User Management';
    }

    public static function canViewAny(): bool       { return Auth::user()?->can('view-any permission') ?? false; }
    public static function canCreate(): bool        { return Auth::user()?->can('create permission') ?? false; }
    public static function canEdit($r): bool        { return Auth::user()?->can('update permission') ?? false; }
    public static function canDelete($r): bool      { return Auth::user()?->can('delete permission') ?? false; }

    public static function getPages(): array
    {
        return [
            'index' => ManagePermissions::route('/'),
        ];
    }
}