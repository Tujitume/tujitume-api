<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => "{$org_name} added you as a supplier"])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi {{ $recipientName }},</p>

        <p><strong>{{ $org_name }}</strong> has added <strong>{{ $supplier_name }}</strong> to their supplier list on Tujitume, so they can pay you directly for work and goods you supply to them.</p>

        <div style="background-color:#f3f4f6;padding:1rem;border-radius:0.5rem;margin:1.5rem 0;">
            <p style="margin:0.5rem 0;"><strong>Organization:</strong> {{ $org_name }}</p>
            <p style="margin:0.5rem 0;"><strong>Added by:</strong> {{ $added_by }}</p>
            <p style="margin:0.5rem 0;"><strong>Supplier:</strong> {{ $supplier_name }}</p>
            @if (!empty($supplier_type))
                <p style="margin:0.5rem 0;"><strong>Type:</strong> {{ $supplier_type }}</p>
            @endif
        </div>

        @if (!empty($onboarded))
            <p>You already have a Tujitume account, so there is nothing more to set up. Log in any time to see your payments and updates from {{ $org_name }}.</p>

            @include('programs.partials.brand_button', ['label' => 'Open Tujitume', 'url' => rtrim((string) config('app.app_url'), '/')])
        @else
            <p>Create your free Tujitume account to see your payments, keep your details up to date and receive updates from {{ $org_name }}.</p>

            @include('programs.partials.brand_button', ['label' => 'Join ' . $org_name . ' on Tujitume', 'url' => rtrim((string) config('app.app_url'), '/') . '/auth/create/service'])
        @endif

        <p style="margin-top:1.5rem;font-size:12px;color:gray;">
            Not expecting this? You can ignore this email, or let {{ $org_name }} know.
        </p>

        @include('programs.partials.brand_footer')
        <p style="margin:0.5rem 0 0;font-size:11px;color:#9ca3af;text-align:center;">Sent on behalf of {{ $org_name }} &middot; Powered by Tujitume</p>
    </div>
</div>
