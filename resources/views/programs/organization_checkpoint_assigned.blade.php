<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Checkpoint Assigned to You'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName ?? 'there' }},</p>

        <p>The monitoring checkpoint <strong>{{ $checkpoint_name }}</strong> for <strong>{{ $program_title }}</strong> was assigned to you.</p>
        @if (!empty($due_date))
            <p>Please complete it by <strong>{{ $due_date }}</strong>.</p>
        @endif
        <p>You will find it under Pending requests, or open the program&rsquo;s monitoring page.</p>

        @include('programs.partials.brand_button', ['label' => 'Open Checkpoint'])

        @include('programs.partials.brand_footer')
    </div>
</div>
