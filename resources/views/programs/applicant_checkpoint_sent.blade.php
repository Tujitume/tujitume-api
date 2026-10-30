<p>Hello {{ $recipientName ?? 'Applicant' }},</p>
<p>A new monitoring checkpoint, <strong>{{ $checkpoint_name }}</strong>, is ready for you under {{ $program_title }}.</p>
@if(!empty($due_date))<p>Please submit it by {{ $due_date }}.</p>@endif
