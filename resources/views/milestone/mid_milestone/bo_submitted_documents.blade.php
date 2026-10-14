<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Progress Update Ready'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hello {{ $investorName }},</p>

        <p>The Business Owner has submitted the mid-milestone progress materials for <strong>{{ $milestoneName }}</strong>.</p>
        <p>Please log in to review the update and cast your vote regarding the release of the remaining funds.</p>

        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ $reviewUrl }}" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);">Review Update</a>
        </div>

        @include('programs.partials.brand_footer')
    </div>
</div>
