<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    <div style="background-color:#14532d;padding:0.9rem 0;text-align:center;color:#ffffff;">
        <img src="{{ $message->embed(public_path('images/Email/EmailWhite.png')) }}" alt="Tujitume Logo" style="height:3rem;width:auto;margin:0 auto;" />
        <h1 style="font-size:2rem;font-weight:700;margin-top:1rem;">Your Payment Is on the Way</h1>
    </div>

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi {{ $recipientName }},</p>

        <p>A payment for <strong>{{ $supplier_name }}</strong> is now being processed.</p>
        <p>Payment amount: <strong>{{ $amount }}</strong></p>

        @if (!empty($payment_reference))
            <p>Payment reference: <strong>{{ $payment_reference }}</strong></p>
        @endif

        <p>We will notify you when the transfer is complete.</p>

        <div style="margin-top:2rem;font-size:12px;color:gray;">
            <img src="{{ $message->embed(public_path('images/Email/EmailVertDark.png')) }}" alt="Tujitume Logo" style="height:3rem;width:auto;float:left;margin-right:1rem;margin-top:-0.2rem;margin-bottom:4rem;" />
            <p style="font-weight:600;">Best regards,<br/>The Tujitume Team</p>
        </div>
    </div>
</div>