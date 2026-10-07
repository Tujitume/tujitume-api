<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:0.5rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Deposit Funds to Start Reviews'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi {{ $recipientName }},</p>

        <p>A reviewer has accepted the assignment for <strong>{{ $round_name }}</strong> in <strong>{{ $program_title }}</strong>. Deposit the required funds into your program wallet so reviewers can start reviewing applications.</p>

        <div style="background-color:#fff3cd;padding:1rem;border-radius:0.5rem;margin:1.5rem 0;border-left:4px solid #ffc107;">
            <p style="margin:0.5rem 0;"><strong>Required for reviewers:</strong> {{ $required }} {{$currency}}</p>
            <p style="margin:0.5rem 0;"><strong>Available in wallet:</strong> {{ $available }} {{$currency}}</p>
            <p style="margin:0.5rem 0;"><strong>Amount to deposit:</strong> {{ $shortfall }} {{$currency}}</p>
        </div>

        @include('programs.partials.brand_button', ['label' => 'Deposit Funds'])

        @include('programs.partials.brand_footer')
    </div>
</div>