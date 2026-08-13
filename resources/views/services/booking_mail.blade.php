

    <!-- Booking accepted -->
   <div
    style="
        max-width: 1024px;
        margin-left: auto;
        margin-right: auto;
        margin-top: 4rem;
        background-color: white;
        border-radius: 0.5rem;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        overflow: hidden;
        position: relative;
    "
>
    <!-- Header with Logo -->
      <div
        style="
            background-color: #14532d;
            padding: 0.9rem 0;
            text-align: center;
            color: #ffffff;
            position: relative;
            z-index: 10;
        "
    >
        <img
             src="{{ $message->embed(config('app.api_base_url') . 'images/Email/EmailWhite.png')}}"

            alt="Company Logo"
            style="height: 3rem; width: auto; margin: 0 auto"
        />
        <h1
            style="font-size: 2rem; font-weight: 700; margin-top: 1rem"
        >
            Your Booking Accepted
        </h1>
    </div>
    <div>
    @if($reason == 0)
        <div style="padding: 30px 20px; font-family: Arial, sans-serif;">
            <h2 style="
                font-size: 24px;
                margin-bottom: 25px;
                color: #2f9f1f;
                font-weight: 600;
            ">Booking Accepted</h2>
            <p style="
                font-size: 16px;
                line-height: 1.6;
                margin-bottom: 25px;
                color: #333;
            ">
                Dear Customer,<br/>We are pleased to inform you that your booking request for <strong>{{$business_name}}</strong> has been accepted by the service owner.
            </p>

            <h3 style="
                font-size: 18px;
                margin: 25px 0 15px 0;
                color: #333;
                font-weight: 600;
            ">Booking Details:</h3>

            <div style="
                background: #f8f9fa;
                padding: 20px;
                border-radius: 8px;
                border-left: 4px solid #2f9f1f;
                margin: 20px 0;
            ">
                <p style="font-size: 14px; line-height: 1.6; margin-bottom: 8px; color: #333;">

                 <b>Booking ID:</b> #OO{{$id}}<br>
                 <b>Service Name:</b> {{$business_name}}<br>
                 <b>Requested Date:</b> {{$date}}<br>

                 @php $Tax = $amount*0.05; $Total = $amount+$Tax; @endphp
                <strong>Amount:</strong> {{$amount}} <br/>
                <strong>Jitume Fee:</strong> {{$Tax}} <br/>
                <strong>Total:</strong> {{$Total}}<br>
            </p>

            <p class="email-message" style="font-size: 12px; padding-top: 10px; line-height: 1.8; margin-bottom: 15px;">
                <b>Payment deadline: {{$payment_deadline}}, </b> <br/>
                if not paid by the deadline, the booking will be automatically cancelled.
            </p>

            </div>

            <h3 style="
                font-size: 18px;
                margin: 25px 0 15px 0;
                color: #333;
                font-weight: 600;
            ">Next Steps:</h3>
            <p style="
                font-size: 14px;
                line-height: 1.6;
                margin-bottom: 20px;
                color: #666;
            ">If you no longer wish to proceed, you may cancel the booking by clicking 'Cancel'</p>

            <div class="button-container" style="display: flex; margin-top: 20px; gap: 20px; align-items: center;">
    <!-- Pay Button -->
    <a
        target="_blank"
        href="<?php echo config('app.app_url');?>service-milestones/{{$s_id}}"
        style="
            display: inline-block;
            padding: 10px 24px;
            text-decoration: none;
            color: #fff;
            border-radius: 6px;
            transition: all 0.3s ease;
            background-color: #2f9f1f;
            text-align: center;
            font-weight: 500;
            border: 1px solid #2f9f1f;
            font-size: 0.875rem;
        "
        onmouseover="this.style.backgroundColor='#26801a';"
        onmouseout="this.style.backgroundColor='#2f9f1f';"
        onfocus="this.style.boxShadow='0 0 0 3px rgba(47, 159, 31, 0.3)';"
        onblur="this.style.boxShadow='none';"
    >
        Pay Here
    </a>

    <!-- Cancel Button -->
    <a
        target="_blank"
        href="<?php echo config('app.api_url');?>CancelBookingConfirm/{{$booking_id}}/confirm"
        style="
            display: inline-block;
            padding: 10px 24px;
            text-decoration: none;
            color: #e11d48;
            border-radius: 6px;
            transition: all 0.3s ease;
            background-color: transparent;
            text-align: center;
            font-weight: 500;
            border: 1px solid #e11d48;
            font-size: 0.875rem;
        "
        onmouseover="this.style.backgroundColor='#e11d48'; this.style.color='white';"
        onmouseout="this.style.backgroundColor='transparent'; this.style.color='#e11d48';"
        onfocus="this.style.boxShadow='0 0 0 3px rgba(225, 29, 72, 0.3)';"
        onblur="this.style.boxShadow='none';"
    >
        Cancel
    </a>
</div>


            <p style="
                font-size: 14px;
                line-height: 1.6;
                margin: 25px 0;
                color: #666;
                text-align: center;
            ">If you need assistance, feel free to reach out to us at <a href="mailto:support@tujitume.com" style="color: #2f9f1f; text-decoration: none;">support@tujitume.com</a></p>

            <div style="
                margin-top: 40px;
                padding-top: 20px;
                border-top: 1px solid #e5e5e5;
                text-align: left;
                color: #666;
                font-size: 14px;
            ">
                <img
                    src="{{ $message->embed(config('app.api_base_url') . 'images/Email/EmailVertDark.png')}}"
                    alt="Company Logo"
                    style="
                        height: 48px;
                        width: auto;
                        margin-bottom: 15px;
                    "
                />
                <p style="
                    font-weight: 600;
                    margin: 0;
                    line-height: 1.5;
                    color: #333;
                ">
                    Best regards,<br/>
                    <span style="color: #2f9f1f;">The Tujitume Team</span>
                </p>
            </div>
        </div>
    @else
      <hr style="border: 1px solid #ccc; margin: 30px 0;">

        <div style="padding: 30px 20px; font-family: Arial, sans-serif;">
            <h2 style="
                font-size: 24px;
                margin-bottom: 25px;
                color: #e11d48;
                font-weight: 600;
            ">Booking Rejected</h2>
            <p style="
                font-size: 16px;
                line-height: 1.6;
                margin-bottom: 25px;
                color: #333;
            ">
                Dear Customer,<br/>Your booking request for the service <strong>{{$business_name}}</strong> has been rejected by the service owner.<br/>Reason: {{$reason}}
            </p>

            <h3 style="
                font-size: 18px;
                margin: 25px 0 15px 0;
                color: #333;
                font-weight: 600;
            ">Booking Details:</h3>

            <div style="
                background: #f8f9fa;
                padding: 20px;
                border-radius: 8px;
                border-left: 4px solid #e11d48;
                margin: 20px 0;
            ">
                <p style="font-size: 14px; line-height: 1.6; color: #333;">

                 <b>Booking ID:</b> #OO{{$id}}<br>
                 <b>Service Name:</b> {{$business_name}}<br>
                 <b>Requested Date:</b> {{$date}}<br>

                If you believe this rejection was made in error, please contact the service owner directly
                through Tujitume support.
            </p>



            <div class="button-container" style="display: flex;gap: 20px;  margin-top: 20px;"> <!-- https://test.jitume.com -->


            </div>
                If you believe this rejection was made in error, please contact the service owner directly through Tujitume support.
                </p>
            </div>

            <div style="
                margin-top: 40px;
                padding-top: 20px;
                border-top: 1px solid #e5e5e5;
                text-align: left;
                color: #666;
                font-size: 14px;
            ">
                <img
                    src="{{ $message->embed(config('app.api_base_url') . 'images/Email/EmailVertDark.png')}}"
                    alt="Company Logo"
                    style="
                        height: 48px;
                        width: auto;
                        margin-bottom: 15px;
                    "
                />
                <p style="
                    font-weight: 600;
                    margin: 0;
                    line-height: 1.5;
                    color: #333;
                ">
                    Best regards,<br/>
                    <span style="color: #2f9f1f;">The Tujitume Team</span>
                </p>
            </div>
        </div>

    @endif
      <hr style="border: 1px solid #ccc; margin: 30px 0;">

    </div>


    </div>






<script src="https://code.jquery.com/jquery-3.4.1.min.js" integrity="sha256-CSXorXvZcTkaix6Yvo6HppcZGetbYMGWSFlBw8HfCJo=" crossorigin="anonymous"></script>


<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.0/dist/umd/popper.min.js" integrity="sha384-Q6E9RHvbIyZFJoft+2mJbHaEWldlvI9IOYy5n3zV9zzTtmI3UksdQRVvoxMfooAo" crossorigin="anonymous"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.4.1/js/bootstrap.min.js" integrity="sha384-wfSDF2E50Y2D1uUdj0O3uMBJnjuUD4Ih7YwaYd1iqfktj0Uod8GCExl3Og8ifwB6" crossorigin="anonymous"></script>



<!--Hidden Cart view-->
