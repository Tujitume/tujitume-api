<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Milestone Funds Approved'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hello {{ $boName }},</p> <p> We’re pleased to inform you that a
            @if( $release_type == 'pre' )
                <strong>pre-release (75%)</strong>
            @else
                <strong>mid milestone release (25%)</strong>
            @endif
            amount for the milestone
            <strong>{{ $milestoneTitle }}</strong> has been successfully approved and released. </p>
        <p> <strong>Milestone Amount:</strong> {{ $amount }}<br/>
            <strong>Released Amount:</strong> {{ $released_amount }} </p>
        <p> You can track this release and view milestone progress directly from your dashboard. </p>
        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ $dashboardUrl }}" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);"> Go to Dashboard </a>
        </div>

        @include('programs.partials.brand_footer')
    </div>
</div>
