<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Project Manager Assigned'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi,</p>
        @if($mail_to == 'owner')
            <p>Project Manager <strong>{{ $manager_name }}</strong> has been assigned to help verify the equipment from the investor <strong>{{ $investor_name }}</strong>. You can start milestone work.</p>

        <div style="background-color:#f0fdf4;padding:1rem 1.25rem;border-radius:0.5rem;margin:1.5rem 0;border-left:4px solid #14532d;">
            <p style="margin:0.5rem 0;"><strong>Project Manager:</strong> {{ $manager_name }}</p>
            <p style="margin:0.5rem 0;"><strong>Contact:</strong> {{ $contact }}</p>
        </div>
        @else
            <p>You have been assigned to help verify the equipment from the investor <strong>{{ $investor_name }}</strong>. Please verify this alongside the Business Owner from your Tujitume dashboard, or use this contact: <strong>{{ $contact }}</strong>.</p>
        @endif

        @include('programs.partials.brand_button')

        @include('programs.partials.brand_footer')
    </div>
</div>
