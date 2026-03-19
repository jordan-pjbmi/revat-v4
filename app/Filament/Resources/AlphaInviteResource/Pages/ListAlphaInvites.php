<?php

namespace App\Filament\Resources\AlphaInviteResource\Pages;

use App\Filament\Resources\AlphaInviteResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAlphaInvites extends ListRecords
{
    protected static string $resource = AlphaInviteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
