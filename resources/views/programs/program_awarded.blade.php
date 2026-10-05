<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Awardees Selected! 🎉'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>

        <p>All application rounds for <strong>{{ $program_title }}</strong> have been completed and awardees have been finalized. Funding setup is now ready to begin.</p>

        <div style="background-color:#f3f4f6;padding:1rem;border-radius:0.5rem;margin:1.5rem 0;">
            <p style="margin:0.5rem 0;"><strong>Program:</strong> {{ $program_title }}</p>
            <p style="margin:0.5rem 0;"><strong>Awardees Selected:</strong> {{ $awarded_count }}</p>
            <p style="margin:0.5rem 0;"><strong>Total Funding:</strong> {{ $total_amount }}</p>
        </div>

        <p>The selected businesses are now ready to begin their funding setup. Please log in to review and approve their milestone plans.</p>

        @include('programs.partials.brand_button', ['label' => 'Go to Funding Setup'])

        @include('programs.partials.brand_footer')
    </div>
</div>
