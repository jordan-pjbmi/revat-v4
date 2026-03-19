<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AlphaInviteResource\Pages;
use App\Models\AlphaInvite;
use App\Services\AlphaInviteService;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class AlphaInviteResource extends Resource
{
    protected static ?string $model = AlphaInvite::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationLabel = 'Alpha Invites';

    protected static string|\UnitEnum|null $navigationGroup = 'Alpha';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('email')
                ->email()
                ->required()
                ->maxLength(254)
                ->unique(AlphaInvite::class, 'email'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('last_sent_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->getStateUsing(fn (AlphaInvite $record) => $record->status())
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'registered' => 'success',
                        'revoked' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('agreement_signed')
                    ->getStateUsing(fn (AlphaInvite $record) => $record->hasSignedAgreement())
                    ->boolean()
                    ->label('Agreement'),
                Tables\Columns\TextColumn::make('registered_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'registered' => 'Registered',
                        'revoked' => 'Revoked',
                    ])
                    ->query(function ($query, array $data) {
                        return match ($data['value']) {
                            'pending' => $query->whereNull('registered_at')->whereNull('revoked_at'),
                            'registered' => $query->whereNotNull('registered_at'),
                            'revoked' => $query->whereNotNull('revoked_at'),
                            default => $query,
                        };
                    }),
            ])
            ->actions([
                Action::make('resend')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->visible(fn (AlphaInvite $record) => $record->isPending())
                    ->action(function (AlphaInvite $record) {
                        app(AlphaInviteService::class)->resend($record);
                        Notification::make()->title('Invite resent')->success()->send();
                    }),
                Action::make('revoke')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (AlphaInvite $record) => $record->isPending())
                    ->action(function (AlphaInvite $record) {
                        app(AlphaInviteService::class)->revoke($record);
                        Notification::make()->title('Invite revoked')->success()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAlphaInvites::route('/'),
            'create' => Pages\CreateAlphaInvite::route('/create'),
        ];
    }
}
