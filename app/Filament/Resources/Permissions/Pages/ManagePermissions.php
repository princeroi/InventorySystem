<?php

namespace App\Filament\Resources\Permissions\Pages;

use App\Filament\Resources\Permissions\PermissionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Auth;

class ManagePermissions extends ManageRecords
{
    protected static string $resource = PermissionResource::class;

    // ✅ This blocks direct URL access
    public function mount(): void
    {
        abort_unless(Auth::user()?->can('view-any permission'), 403);
        parent::mount();
    }

    private function userCan(string $permission): bool
    {
        return Auth::user()?->can($permission) ?? false;
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->visible(fn () => $this->userCan('create permission')),
        ];
    }
}