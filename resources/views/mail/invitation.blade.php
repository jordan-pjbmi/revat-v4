<p>You've been invited to join <strong>{{ $invitation->organization->name }}</strong> on Revat as {{ ucfirst($invitation->role) }}.</p>

@if ($invitation->invitedBy)
    <p>Invited by {{ $invitation->invitedBy->name }}.</p>
@endif

<p>Click the link below to accept your invitation:</p>

<p><a href="{{ $acceptUrl }}">Accept Invitation</a></p>

<p>This invitation expires in 7 days.</p>
