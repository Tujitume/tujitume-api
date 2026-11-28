<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Your import is finished'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi {{ $recipientName }},</p>

        <p>Your program import{{ !empty($file_name) ? ' ('.$file_name.')' : '' }} is finished.</p>

        <div style="background-color:#f3f4f6;padding:1rem;border-radius:0.5rem;margin:1.5rem 0;">
            <p style="margin:0.5rem 0;"><strong>Programs created:</strong> {{ $created }} of {{ $total }}</p>
            @if ((int) ($failed ?? 0) > 0)
                <p style="margin:0.5rem 0;"><strong>Need attention:</strong> {{ $failed }} (open the import to see why, fix them and try again)</p>
            @endif
            <p style="margin:0.5rem 0;"><strong>Time taken:</strong> {{ $duration }}</p>
        </div>

        <p>The programs are hidden drafts until you publish them. Nothing was sent to applicants, Business Coaches or suppliers: you choose when to tell them from the import.</p>

        @include('programs.partials.brand_button', ['label' => 'Open the import'])

        @include('programs.partials.brand_footer')
    </div>
</div>
