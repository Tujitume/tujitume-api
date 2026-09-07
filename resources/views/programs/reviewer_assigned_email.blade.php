<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    <!-- Header -->
    <div style="background-color:#14532d;padding:0.9rem 0;text-align:center;color:#ffffff;">
        <img src="{{ $message->embed(config('app.api_base_url') . 'images/Email/EmailWhite.png') }}" alt="Tujitume Logo" style="height:3rem;width:auto;margin:0 auto;" />
        <h1 style="font-size:2rem;font-weight:700;margin-top:1rem;">Applications to Review</h1>
    </div>


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
            <p style="margin:0.5rem 0;"><strong>Proposed Fee per application:</strong> {{ implode(', ', $proposed_fee) }}</p>
            @endif


        </div>

        <p>Pleae Log in to your Tujitume dashabord and get started!</p>




        <!-- Footer -->

    </div>
</div>