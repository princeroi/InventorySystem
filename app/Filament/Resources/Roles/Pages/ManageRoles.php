<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Auth;

class ManageRoles extends ManageRecords
{
    protected static string $resource = RoleResource::class;

    // ✅ This blocks direct URL access
    public function mount(): void
    {
        abort_unless(Auth::user()?->can('view-any role'), 403);
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
                ->visible(fn () => $this->userCan('create role')),
        ];
    }
}