<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => $title])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi {{ $recipientName }},</p>

        <p>{{ $summary }}</p>

        @if (!empty($details))
            <div style="background-color:#f3f4f6;padding:1rem;border-radius:0.5rem;margin:1.5rem 0;">
                @foreach ($details as $label => $value)
                    <p style="margin:0.5rem 0;"><strong>{{ $label }}:</strong> {{ $value }}</p>
                @endforeach
            </div>
        @endif

        @if (($show_action ?? true) === false)
            {{-- nothing further to do for this supplier --}}
        @elseif (empty($onboarded))
            <p>Create your free Tujitume account to follow your payments and manage your details with {{ $org_name }}.</p>
            @include('programs.partials.brand_button', ['label' => 'Join ' . $org_name . ' on Tujitume', 'url' => rtrim((string) config('app.app_url'), '/') . '/auth/create/service'])
        @else
            @include('programs.partials.brand_button', ['label' => 'Open Tujitume', 'url' => rtrim((string) config('app.app_url'), '/')])
        @endif

        <p style="margin-top:1.5rem;font-size:12px;color:gray;">
            If this doesn't look right, please contact {{ $org_name }} directly.
        </p>

        @include('programs.partials.brand_footer')
        <p style="margin:0.5rem 0 0;font-size:11px;color:#9ca3af;text-align:center;">Sent on behalf of {{ $org_name }} &middot; Powered by Tujitume</p>
    </div>
</div>
