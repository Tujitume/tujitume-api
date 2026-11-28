<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Terms Agreement Offer'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi,</p>
        @if($status && $status == 'accepted')
            <p>A terms &amp; agreement offer was <strong>accepted</strong> for Capital <strong>{{ $capital }}</strong> and Startup <strong>{{ $startup }}</strong>.</p>
        @elseif($status && $status == 'rejected')
            <p>A terms &amp; agreement offer was <strong>rejected</strong> for Capital <strong>{{ $capital }}</strong> and Startup <strong>{{ $startup }}</strong>.</p>
        @elseif($status && $status == 'counter_submitted')
            <p>A <strong>counter-offer</strong> was sent for Capital <strong>{{ $capital }}</strong> and Startup <strong>{{ $startup }}</strong>.</p>
        @else
            <p>A terms &amp; agreement offer was <strong>submitted</strong> for Capital <strong>{{ $capital }}</strong> and Startup <strong>{{ $startup }}</strong>.</p>
        @endif
        <p>Please review it on your Tujitume dashboard.</p>

        @include('programs.partials.brand_button', ['label' => 'Review Offer'])

        @include('programs.partials.brand_footer')
    </div>
</div>
