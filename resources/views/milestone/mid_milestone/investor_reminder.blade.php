<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Action Required'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hello {{ $investorName }},</p>

        <p>You have a pending vote on the mid-milestone progress update for <strong>{{ $milestoneName }}</strong>.</p>

        <p>This is your <strong>Day {{ $reminderDay }}</strong> reminder.</p>

        @if($reminderDay == 10)
            <p><strong>A prompt response is required to avoid automatic action.</strong></p>
        @endif

        @if($reminderDay == 14)
            <p><strong>No vote was submitted. Your vote has been auto-approved as per platform policy.</strong></p>
        @endif

        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ $reviewUrl }}" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);">Submit Vote</a>
        </div>

        @include('programs.partials.brand_footer')
    </div>
</div>
