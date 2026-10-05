<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Report Submitted'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi,</p>
        <p>Thank you for reporting the listing titled <strong>{{ $listing_name }}</strong> on Tujitume. Your report has been received and is under review by our moderation team.</p>

        <div style="background-color:#f3f4f6;padding:1rem 1.25rem;border-radius:0.5rem;margin:1.5rem 0;">
            <p style="margin:0.5rem 0;"><strong>Report ID:</strong> #{{ $id }}</p>
            <p style="margin:0.5rem 0;"><strong>Reported Listing:</strong> {{ $listing_name }}</p>
            <p style="margin:0.5rem 0;"><strong>Report Category:</strong> {{ $category }}</p>
        </div>

        <p>We will update you as soon as we complete the review process.</p>

        @include('programs.partials.brand_footer')
    </div>
</div>
