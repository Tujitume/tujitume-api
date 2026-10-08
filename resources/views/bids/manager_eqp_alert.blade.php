<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Equipment Released'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi,</p>
        <p>Equipment from <strong>{{ $investor_name }}</strong> for the business <strong>{{ $b_name }}</strong> has been released. The process has begun, and the contact information of the parties involved is below.</p>

        <div style="background-color:#f0fdf4;padding:1rem 1.25rem;border-radius:0.5rem;margin:1.5rem 0;border-left:4px solid #14532d;">
            <p style="margin:0.5rem 0;">
                @if($to == 'BO')
                    <strong>Business Owner:</strong> {{ $owner_name }} &mdash; {{ $contact2 }}
                @else
                    <strong>Project Manager:</strong> {{ $owner_name }} &mdash; {{ $contact2 }}
                @endif
            </p>
            <p style="margin:0.5rem 0;"><strong>Investor:</strong> {{ $investor_name }} &mdash; {{ $contact }}</p>
        </div>

        <p>Thank you!</p>

        @include('programs.partials.brand_button')

        @include('programs.partials.brand_footer')
    </div>
</div>
