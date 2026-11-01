<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'New Monitoring Checkpoint'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName ?? 'there' }},</p>

        <p>A new monitoring checkpoint, <strong>{{ $checkpoint_name }}</strong>, is ready for you under <strong>{{ $program_title }}</strong>.</p>
        @if (!empty($due_date))
            <p>Please submit it by <strong>{{ $due_date }}</strong>.</p>
        @endif
        <p>Open it to see what is asked of you and what to submit.</p>

        @include('programs.partials.brand_button', ['label' => 'Open Checkpoint'])

        @include('programs.partials.brand_footer')
    </div>
</div>
