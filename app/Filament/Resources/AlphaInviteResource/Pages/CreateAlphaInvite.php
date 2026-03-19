<?php

namespace App\Filament\Resources\AlphaInviteResource\Pages;

use App\Filament\Resources\AlphaInviteResource;
use App\Services\AlphaInviteService;
use Filament\Resources\Pages\CreateRecord;

class CreateAlphaInvite extends CreateRecord
{
    protected static string $resource = AlphaInviteResource::class;

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        return app(AlphaInviteService::class)->create($data['email']);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Alpha invite sent';
    }
}
