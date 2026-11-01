<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Fee Proposal Answered'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName ?? 'there' }},</p>

        @if ($accepted ?? false)
            <p>The organization <strong>accepted</strong> your proposed fee for the site visit under <strong>{{ $program_title ?? 'the program' }}</strong>. You can now accept the visit.</p>
        @else
            <p>The organization <strong>declined</strong> your proposed fee for the site visit under <strong>{{ $program_title ?? 'the program' }}</strong>.</p>
            <p>You can accept the visit at the original fee, or decline it.</p>
        @endif

        @include('programs.partials.brand_button', ['label' => 'Open Site Visit'])

        @include('programs.partials.brand_footer')
    </div>
</div>
