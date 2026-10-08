<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Program Awarded!'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>

        <p>We are thrilled to inform you that you've been awarded from <strong>{{ $program_title }}</strong>! 🎉</p>

        <div style="background-color:#f3f4f6;padding:1rem;border-radius:0.5rem;margin:1.5rem 0;">
            <p style="margin:0.5rem 0;"><strong>Program:</strong> {{ $program_title }}</p>
            <p style="margin:0.5rem 0;"><strong>Next Step:</strong> Funding Setup</p>
        </div>

        <p>Your milestones have been created. You can now begin working on your first milestone.</p>

        @include('programs.partials.brand_button', ['label' => 'View Milestones'])

        @include('programs.partials.brand_footer')
    </div>
</div>
