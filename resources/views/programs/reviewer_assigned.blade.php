<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Applications to Review'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>

        <p>You have been assigned <strong>{{ $count }}</strong> applications to review for <strong>{{ $program_title }} Program</strong>.</p>

        <p>Please complete your reviews before the deadline.</p>

        @include('programs.partials.brand_button', ['label' => 'Start Reviewing'])

        @include('programs.partials.brand_footer')
    </div>
</div>
