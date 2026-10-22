<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'Milestone Supplier Assignment'])
    
    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <!-- Body -->
        <p>Hi {{ $recipientName }},</p>
        
        <p><strong>{{ $org_name ?? 'The program team' }}</strong> has added you as a supplier to Milestone <strong>{{ $milestone_number }}</strong> for <strong>{{ $program_title ?? 'the program' }}</strong>.</p>

        @include('programs.partials.brand_button', ['label' => 'View Your Account'])

        @include('programs.partials.brand_footer')
    </div>
</div>
