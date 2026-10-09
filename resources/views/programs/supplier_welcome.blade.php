<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => "You've Been Added as a Supplier"])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi {{ $recipientName }},</p>

        <p><strong>{{ $added_by }}</strong> from <strong>{{ $org_name }}</strong> has added <strong>{{ $supplier_name }}</strong> as a supplier on Tujitume.</p>

        <p>Tujitume is a program and capital funding platform that connects businesses with funding opportunities. As a registered supplier, you may receive payments directly through the platform.</p>

        <div style="background-color:#f3f4f6;padding:1rem;border-radius:0.5rem;margin:1.5rem 0;">
            <p style="margin:0.5rem 0;"><strong>Added By:</strong> {{ $added_by }}</p>
            <p style="margin:0.5rem 0;"><strong>Organization:</strong> {{ $org_name }}</p>
            <p style="margin:0.5rem 0;"><strong>Supplier Name:</strong> {{ $supplier_name }}</p>
            <p style="margin:0.5rem 0;"><strong>Nominated Supplier Type:</strong> {{ $supplier_type ?? 'N/A' }}</p>
        </div>

        <p>Join Tujitume today to track your payments, manage your profile, and access more opportunities.</p>

        @include('programs.partials.brand_button', ['label' => 'Join Tujitume', 'url' => config('app.app_url') . 'auth/create/service'])

        <p style="margin-top:1.5rem;font-size:12px;color:gray;">
            If you have any questions or did not expect this email, please contact us at
            <a href="mailto:support@tujitume.com" style="color:#14532d;">support@tujitume.com</a>
        </p>

        @include('programs.partials.brand_footer')
    </div>
</div>
