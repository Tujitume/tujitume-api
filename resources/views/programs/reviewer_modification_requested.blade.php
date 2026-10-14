<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Modification Requested'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>

        <p>The program owner has requested modifications to your work on <strong>{{ $program_title }}</strong>.</p>

        <p><strong>Requested Changes:</strong></p>
        <p>{{ $modification_note }}</p>

        <p>Please review the feedback and resubmit your work when ready.</p>

        @include('programs.partials.brand_button', ['label' => 'View Details'])

        @include('programs.partials.brand_footer')
    </div>
</div>
