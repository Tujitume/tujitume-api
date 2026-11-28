@php($badge = match ($r->status) {
    'pending' => 'badge-warning',
    'trial_active', 'full_access_requested', 'registered' => 'badge-info',
    'proposal_sent', 'converted' => 'badge-success',
    default => 'badge-secondary',
})
<span class="badge {{ $badge }}">{{ $r->statusLabel() }}</span>
