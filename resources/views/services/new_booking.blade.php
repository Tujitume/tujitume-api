<div style="max-width:1024px;margin:auto;margin-top:4rem;background-color:white;border-radius:1.25rem;box-shadow:0 4px 6px rgba(0,0,0,0.1);overflow:hidden;">
    @include('programs.partials.brand_header', ['title' => 'You Have a New Booking'])

    <div style="padding:20px;font-size:14px;line-height:1.6;">
        <p>Hi,</p>
        <p>You have a new booking for the service <strong>{{ $business_name }}</strong>. Please go to your dashboard and review it.</p>

        <div style="background-color:#fff3cd;padding:1rem 1.25rem;border-radius:0.5rem;margin:1.5rem 0;border-left:4px solid #ffc107;">
            <p style="margin:0.5rem 0;"><strong>Booking accept due in:</strong> {{ $accept_deadline }}</p>
            <p style="margin:0.5rem 0;">If not accepted and paid by the deadline, the booking will be automatically cancelled.</p>
        </div>
        <div style="text-align:center;margin-top:2rem;">
            <a href="https://beta.tujitume.com/dashboard/mybookings" style="background-color:#14532d;color:white;display:inline-block;padding:0.85rem 2rem;border-radius:999px;text-decoration:none;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.18);">View My Bookings</a>
        </div>

        @include('programs.partials.brand_footer')
    </div>
</div>
