<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'New Demo Request'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi Tujitume Team,</p>
        <p>You have received a new demo request. Please find the details below:</p>

        <div style="background-color:#f3f4f6;padding:1rem 1.25rem;border-radius:0.5rem;margin:1.5rem 0;">
            <p style="margin:0.5rem 0;"><strong>Name:</strong> {{ $name }}</p>
            <p style="margin:0.5rem 0;"><strong>Organization:</strong> {{ $org }}</p>
            <p style="margin:0.5rem 0;"><strong>Email:</strong> {{ $email }}</p>
            <p style="margin:0.5rem 0;"><strong>Request Type:</strong> {{ ucfirst($request_type) }}</p>
            <p style="margin:0.5rem 0;"><strong>Type:</strong> {{ ucfirst($type) }}</p>
            @if(!empty($notes))
            <p style="margin:0.5rem 0;"><strong>Notes:</strong> {{ $notes }}</p>
            @endif
        </div>

        <p>Please follow up with this request at your earliest convenience.</p>

        <div style="text-align:center;margin-top:2rem;">
            <a href="mailto:{{ $email }}" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);">Reply to {{ $name }}</a>
        </div>

        @include('programs.partials.brand_footer')
    </div>
</div>
