{{-- A plain program notice: $email_title, $email_message and an optional $action_label for the button. --}}
<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => $email_title ?? 'Update'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi {{ $recipientName }},</p>

        <p>{{ $email_message ?? '' }}</p>

        @include('programs.partials.brand_button', ['label' => $action_label ?? null])

        @include('programs.partials.brand_footer')
    </div>
</div>
