<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    <!-- Header -->
    <div style="background-color:#14532d;padding:0.9rem 0;text-align:center;color:#ffffff;">
        <img src="{{ $message->embed(config('app.api_base_url') . 'images/Email/EmailWhite.png') }}" alt="Tujitume Logo" style="height:3rem;width:auto;margin:0 auto;" />
        <h1 style="font-size:2rem;font-weight:700;margin-top:1rem;">You've Been Added as a Supplier</h1>
    </div>

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi {{ $recipientName }},</p>

        <p><strong>{{ $added_by }}</strong> from <strong>{{ $org_name }}</strong> has added <strong>{{ $supplier_name }}</strong> as a supplier on Tujitume.</p>

        <p>Tujitume is a grant and capital funding platform that connects businesses with funding opportunities. As a registered supplier, you may receive payments directly through the platform.</p>

        <div style="background-color:#f3f4f6;padding:1rem;border-radius:0.5rem;margin:1.5rem 0;">
            <p style="margin:0.5rem 0;"><strong>Added By:</strong> {{ $added_by }}</p>
            <p style="margin:0.5rem 0;"><strong>Organization:</strong> {{ $org_name }}</p>
            <p style="margin:0.5rem 0;"><strong>Supplier Name:</strong> {{ $supplier_name }}</p>
            <p style="margin:0.5rem 0;"><strong>Nominated Supplier Type:</strong> {{ $supplier_type ?? 'N/A' }}</p>
        </div>

        <p>Join Tujitume today to track your payments, manage your profile, and access more opportunities.</p>

        <div style="text-align:center;margin-top:2rem;">
            <a href="{{ config('app.app_url') }}auth/create/service"
               style="background-color:#14532d;color:white;padding:0.75rem 1.5rem;border-radius:0.5rem;text-decoration:none;font-weight:500;font-size:1rem;">
                Join Tujitume
            </a>
        </div>

        <p style="margin-top:1.5rem;font-size:12px;color:gray;">
            If you have any questions or did not expect this email, please contact us at
            <a href="mailto:support@tujitume.com" style="color:#14532d;">support@tujitume.com</a>
        </p>

        <!-- Footer -->
        <div style="margin-top:2rem;font-size:12px;color:gray;">
            <img src="{{ $message->embed(config('app.api_base_url') . 'images/Email/EmailVertDark.png') }}" alt="Tujitume Logo" style="height:3rem;width:auto;float:left;margin-right:1rem;margin-top:-0.2rem;margin-bottom:4rem;" />
            <p style="font-weight:600;">Best regards,<br/>The Tujitume Team</p>
        </div>
    </div>
</div>
