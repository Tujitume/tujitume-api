{{-- The four applicant emails a program owner may write (ProgramEmailTemplate::CUSTOMISABLE_EVENTS).
     $email_body is the owner's body, or the system default, with its tokens already filled in
     (ProgramEmailDefaults). $email_heading, $email_button and the footer follow $email_locale. --}}
<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;" lang="{{ $email_locale ?? 'en' }}">
    @include('programs.partials.brand_header', ['title' => $email_heading])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        {!! $email_body !!}

        @include('programs.partials.brand_button', ['label' => $email_button, 'locale' => $email_locale ?? 'en'])

        @include('programs.partials.brand_footer', ['locale' => $email_locale ?? 'en'])
    </div>
</div>
