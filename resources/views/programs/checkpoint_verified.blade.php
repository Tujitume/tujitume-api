<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Checkpoint Verified'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName ?? 'there' }},</p>

        <p>The monitoring checkpoint <strong>{{ $checkpoint_name }}</strong> for <strong>{{ $program_title }}</strong> was verified.</p>
        @if (!empty($reviewer_note))
            <p>Note: {{ $reviewer_note }}</p>
        @endif
        <p>Nothing more is needed from you.</p>

        @include('programs.partials.brand_button', ['label' => 'Open Checkpoint'])

        @include('programs.partials.brand_footer')
    </div>
</div>
