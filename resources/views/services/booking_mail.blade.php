<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Booking Update'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        @if($reason == 0)
        <p>Dear Customer,</p>
        <p>We are pleased to inform you that your booking request for <strong>{{ $business_name }}</strong> has been <strong>accepted</strong> by the service owner.</p>

        @php
            $Tax = $amount * 0.05;
            $Total = $amount + $Tax;
        @endphp
        <div style="background-color:#f3f4f6;padding:1rem 1.25rem;border-radius:0.5rem;margin:1.5rem 0;">
            <p style="margin:0.5rem 0;"><strong>Booking ID:</strong> #OO{{ $id }}</p>
            <p style="margin:0.5rem 0;"><strong>Service Name:</strong> {{ $business_name }}</p>
            <p style="margin:0.5rem 0;"><strong>Requested Date:</strong> {{ $date }}</p>
            <p style="margin:0.5rem 0;"><strong>Amount:</strong> {{ $amount }}</p>
            <p style="margin:0.5rem 0;"><strong>Jitume Fee:</strong> {{ $Tax }}</p>
            <p style="margin:0.5rem 0;"><strong>Total:</strong> {{ $Total }}</p>
        </div>
        <div style="background-color:#fff3cd;padding:1rem 1.25rem;border-radius:0.5rem;margin:1.5rem 0;border-left:4px solid #ffc107;">
            <p style="margin:0.5rem 0;"><strong>Payment deadline:</strong> {{ $payment_deadline }}</p>
            <p style="margin:0.5rem 0;">If not paid by the deadline, the booking will be automatically cancelled.</p>
        </div>

        <p>If you no longer wish to proceed, you may cancel the booking using the Cancel button.</p>

        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ config('app.app_url') }}service-milestones/{{ $s_id }}" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);margin:0.25rem;">Pay Here</a>
            <a href="{{ config('app.api_url') }}CancelBookingConfirm/{{ $booking_id }}/confirm" style="background-color:#9f1239;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);margin:0.25rem;">Cancel</a>
        </div>

        <p style="margin-top:1.5rem;">If you need assistance, reach out to us at <a href="mailto:support@tujitume.com" style="color:#14532d;">support@tujitume.com</a>.</p>
        @else
        <p>Dear Customer,</p>
        <p>Your booking request for the service <strong>{{ $business_name }}</strong> has been <strong>rejected</strong> by the service owner.</p>

        <div style="background-color:#fff3cd;padding:1rem 1.25rem;border-radius:0.5rem;margin:1.5rem 0;border-left:4px solid #ffc107;">
            <p style="margin:0.5rem 0;"><strong>Reason:</strong> {{ $reason }}</p>
            <p style="margin:0.5rem 0;"><strong>Booking ID:</strong> #OO{{ $id }}</p>
            <p style="margin:0.5rem 0;"><strong>Service Name:</strong> {{ $business_name }}</p>
            <p style="margin:0.5rem 0;"><strong>Requested Date:</strong> {{ $date }}</p>
        </div>

        <p>If you believe this rejection was made in error, please contact the service owner directly through Tujitume support.</p>
        @endif

        @include('programs.partials.brand_footer')
    </div>
</div>
