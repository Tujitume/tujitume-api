<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Service Payment Received'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        @php
            $Tax = $amount * 0.03;
            $Total = $amount + $Tax;
        @endphp
        <p>Hi,</p>
        <p>We have successfully received payment from <strong>{{ $customer }}</strong> for the service <strong>{{ $business }}</strong>. The payment details are as follows:</p>

        <div style="background-color:#f0fdf4;padding:1rem 1.25rem;border-radius:0.5rem;margin:1.5rem 0;border-left:4px solid #14532d;">
            <p style="margin:0.5rem 0;"><strong>Booking ID:</strong> #OO{{ $id }}</p>
            <p style="margin:0.5rem 0;"><strong>Service Name:</strong> {{ $business }}</p>
            <p style="margin:0.5rem 0;"><strong>Amount:</strong> {{ $amount }}</p>
            <p style="margin:0.5rem 0;"><strong>Tujitume Fee:</strong> {{ $Tax }}</p>
            <p style="margin:0.5rem 0;"><strong>Total:</strong> {{ $Total }}</p>
        </div>

        @if(!empty($note))
            <p>{{ $note }}</p>
        @endif

        <p>Your payment is securely held in escrow and will be released incrementally as milestones are completed.</p>
        <p><strong>Milestone status update:</strong> Milestone 1 is <strong>In Progress</strong>.</p>

        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ config('app.app_url') }}dashboard/messages" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);">View More</a>
        </div>

        <p style="margin-top:1.5rem;">If you have any questions, please contact us at <a href="mailto:support@tujitume.com" style="color:#14532d;">support@tujitume.com</a>. Thank you for choosing Tujitume!</p>

        @include('programs.partials.brand_footer')
    </div>
</div>
