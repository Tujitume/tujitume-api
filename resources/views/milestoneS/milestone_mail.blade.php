<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Service Payment Received'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        @php
            // What was really charged (service fee plus the checkout fees) when the caller knows it; never a guessed fee
            $paidTotal = isset($paid) && $paid !== null ? (float) $paid : null;
            $fees = $paidTotal !== null ? max(0, $paidTotal - (float) $amount) : null;
            $forProvider = ($for ?? null) === 'provider';
        @endphp
        <p>Hi,</p>
        <p>We have successfully received payment from <strong>{{ $customer }}</strong> for the service <strong>{{ $business }}</strong>. The payment details are as follows:</p>

        <div style="background-color:#f0fdf4;padding:1rem 1.25rem;border-radius:0.5rem;margin:1.5rem 0;border-left:4px solid #14532d;">
            <p style="margin:0.5rem 0;"><strong>Booking ID:</strong> #{{ $id }}</p>
            <p style="margin:0.5rem 0;"><strong>Service Name:</strong> {{ $business }}</p>
            <p style="margin:0.5rem 0;"><strong>Service fee:</strong> KES {{ number_format((float) $amount, 2) }}</p>
            @if(! $forProvider && $fees !== null)
                <p style="margin:0.5rem 0;"><strong>Platform and payment fees:</strong> KES {{ number_format($fees, 2) }}</p>
                <p style="margin:0.5rem 0;"><strong>Total paid:</strong> KES {{ number_format($paidTotal, 2) }}</p>
            @endif
        </div>

        @if(!empty($note))
            <p>{{ $note }}</p>
        @endif

        @if($forProvider)
            <p>Tujitume holds the service fee and releases each milestone's amount to your wallet when the customer approves it. You can start the first milestone now.</p>
        @else
            <p>Your payment is securely held in escrow and will be released incrementally as milestones are completed.</p>
        @endif
        <p><strong>Milestone status update:</strong> Milestone 1 is <strong>In Progress</strong>.</p>

        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ $link ?? (($app_url ?? config('app.app_url')).'dashboard/messages') }}" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);">View More</a>
        </div>

        <p style="margin-top:1.5rem;">If you have any questions, please contact us at <a href="mailto:support@tujitume.com" style="color:#14532d;">support@tujitume.com</a>. Thank you for choosing Tujitume!</p>

        @include('programs.partials.brand_footer')
    </div>
</div>
