<p>Hello {{ $recipientName ?? 'Program owner' }},</p>
<p>{{ $reviewer_name }} proposed a different fee for {{ $checkpoint_name }} under {{ $program_title }}.</p>
<p>Current fee: {{ $fee }} {{ $currency }}. Proposed fee: {{ $proposed_fee }} {{ $currency }}.</p>
<p>The visit cannot be accepted until you answer the proposal.</p>
