<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Final Payment Released'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Dear {{ $user_name }},</p>
        <p>The final payment for the service <strong>{{ $service }}</strong> has been successfully released to @if($to == 1) the service owner @else your account @endif.</p>

        <div style="background-color:#f0fdf4;padding:1rem 1.25rem;border-radius:0.5rem;margin:1.5rem 0;border-left:4px solid #14532d;">
            <p style="margin:0.5rem 0;"><strong>Total Amount Released:</strong> {{ $amount }}</p>
        </div>

        <p>The service <strong>{{ $service }}</strong> is now marked as fully completed. If you have any feedback or requests, contact Tujitume support at <a href="mailto:support@tujitume.com" style="color:#14532d;">support@tujitume.com</a>.</p>

        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ config('app.app_url') }}service-details/{{ $s_id }}?review_popup=true" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);">Leave a Review</a>
        </div>

        @include('programs.partials.brand_footer')
    </div>
</div>
