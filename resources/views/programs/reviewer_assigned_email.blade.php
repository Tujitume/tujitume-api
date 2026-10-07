<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Applications to Review'])


    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi {{ $recipientName }},</p>

        <p>You have been requested to review program applications for <strong>{{ $program_title }}</strong> on Tujitume — a program and investment funding platform.</p>

        <div style="background-color:#f3f4f6;padding:1rem;border-radius:0.5rem;margin:1.5rem 0;">
            <p style="margin:0.5rem 0;"><strong>Program:</strong> {{ $program_title }}</p>
            <p style="margin:0.5rem 0;"><strong>Round:</strong> {{ $round_name }}</p>
            @if(!empty($max_apps))
            <p style="margin:0.5rem 0;"><strong>Applications to Review:</strong> {{ $max_apps }}</p>
            @endif
            @if(!empty($expertise_tags))
            <p style="margin:0.5rem 0;"><strong>Expertise Required:</strong> {{ implode(', ', $expertise_tags) }}</p>
            @endif

            @if(!empty($proposed_fee))
            <p style="margin:0.5rem 0;"><strong>Proposed Fee per application:</strong> {{ $proposed_fee }}</p>
            @endif


        </div>

        <p>Please log in to your Tujitume dashboard and get started!</p>

        @include('programs.partials.brand_button', ['label' => 'Start Reviewing'])

        @include('programs.partials.brand_footer')
</div>
</div>
