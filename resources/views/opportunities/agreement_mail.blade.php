<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Terms Agreement Offer'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi,</p>
        @if($status && $status == 'accepted')
            <p>A terms &amp; agreement offer was <strong>accepted</strong> for Capital <strong>{{ $capital }}</strong> and Startup <strong>{{ $startup }}</strong>.</p>
        @elseif($status && $status == 'rejected')
            <p>A terms &amp; agreement offer was <strong>rejected</strong> for Capital <strong>{{ $capital }}</strong> and Startup <strong>{{ $startup }}</strong>.</p>
        @else
            <p>A terms &amp; agreement offer was <strong>submitted</strong> for Capital <strong>{{ $capital }}</strong> and Startup <strong>{{ $startup }}</strong>.</p>
        @endif
        <p>Please review it on your Tujitume dashboard.</p>

        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ config('app.app_url') }}dashboard/pitch-agreements" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);">Review Offer</a>
        </div>

        @include('programs.partials.brand_footer')
    </div>
</div>
