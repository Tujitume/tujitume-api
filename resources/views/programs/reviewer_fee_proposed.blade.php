<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Site Visit Fee Proposed'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName ?? 'there' }},</p>

        <p><strong>{{ $reviewer_name }}</strong> proposed a different fee for <strong>{{ $checkpoint_name ?? 'a site visit' }}</strong> under <strong>{{ $program_title }}</strong>.</p>
        <p>Current fee: <strong>{{ $fee }} {{ $currency }}</strong><br/>Proposed fee: <strong>{{ $proposed_fee }} {{ $currency }}</strong></p>
        <p>The visit cannot be accepted until you accept or decline the proposal.</p>

        @include('programs.partials.brand_button', ['label' => 'Answer Proposal'])

        @include('programs.partials.brand_footer')
    </div>
</div>
