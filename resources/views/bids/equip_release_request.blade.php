<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Equipment Release Request'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi,</p>
        <p>Your bid is now verified. You can proceed to release the equipment.</p>
        <p>Please watch out for milestone completion emails, as the progress of the investment depends on your review.</p>

        @php
            $releaseManager = (!$manager || $manager == '') ? 0 : $manager;
        @endphp
        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ config('app.app_url') }}equipmentRelease/{{ $business_owner }}/{{ $releaseManager }}/{{ $bid_id }}" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);margin:0.25rem;">Proceed to Release Equipment</a>
            <a href="{{ config('app.api_url') }}CancelEquipmentRelease/{{ $bid_id }}/confirm" style="background-color:#9f1239;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);margin:0.25rem;">Cancel</a>
        </div>

        @include('programs.partials.brand_footer')
    </div>
</div>
