<p>Hello {{ $recipientName ?? 'there' }},</p>
<p>The monitoring checkpoint <strong>{{ $checkpoint_name }}</strong> for {{ $program_title }} was assigned to you.</p>
@if(!empty($due_date))<p>Please complete it by {{ $due_date }}.</p>@endif
