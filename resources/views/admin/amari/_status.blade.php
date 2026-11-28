{{-- One Amari request's result. Expects $status. --}}
@php($label = [
    'answered' => ['Answered', 'badge-success'],
    'stopped' => ['Stopped by the person', 'badge-light'],
    'limited' => ['Refused: limit reached', 'badge-danger'],
    'paused' => ['Refused: paused', 'badge-secondary'],
    'blocked' => ['Refused: prompt check', 'badge-warning'],
    'error' => ['Failed', 'badge-dark'],
][$status] ?? [ucfirst($status), 'badge-light'])
<span class="badge {{ $label[1] }}">{{ $label[0] }}</span>
