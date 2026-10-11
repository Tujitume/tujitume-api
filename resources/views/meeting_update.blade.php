<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => $title])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hello {{ $recipientName }},</p>

        <p>{{ $body }}</p>

        <div style="background-color:#f3f4f6;padding:1rem;border-radius:0.5rem;margin:1.5rem 0;">
            <p style="margin:0.5rem 0;"><strong>Meeting:</strong> {{ $meeting_title }}</p>
            <p style="margin:0.5rem 0;"><strong>{{ $cancelled ? 'Was scheduled for' : 'When' }}:</strong> {{ $when }}</p>
            <p style="margin:0.5rem 0;"><strong>With:</strong> {{ $otherName }}</p>
            @if (!empty($description))
                <p style="margin:0.5rem 0;"><strong>Details:</strong> {{ $description }}</p>
            @endif
        </div>

        @if ($cancelled)
            <p>No action is needed. If you still want to meet, you can schedule a new time from Meetings.</p>
        @else
            @include('programs.partials.brand_button', ['label' => 'Join Meeting', 'url' => $joinUrl])
            <p style="margin-top:1.5rem;font-size:13px;color:#6b7280;">You can also find this meeting on your Meetings calendar. If the time does not suit you, message {{ $otherName }} to agree a new one.</p>
        @endif

        @include('programs.partials.brand_footer')
    </div>
</div>
