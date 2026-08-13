<!--Hidden Cart view-->

<div
    style="
        max-width: 1024px;
        margin-left: auto;
        margin-right: auto;
        margin-top: 4rem;
        background-color: white;
        border-radius: 0.5rem;
        border: 1px solid #e0e0e0;
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
        <h1 style="font-size: 2rem; font-weight: 700; margin-top: 1rem">
            Service Payment
        </h1>
    </div>
    <div class="content" style="padding: 20px">
    <h3 style="color: green; font-size: 1.5rem; font-weight: bold; margin-bottom: 15px;">
        Payment Received for Service {{$business}}.
    </h3>

    <div style="background: white; padding: 15px; border-radius: 8px; ;">
        <p style="color: black; font-size: 1rem; font-weight: 500; margin-bottom: 8px;">
            Hi <b>{{$customer}}</b>, my name is <b>{{$s_provider}}</b>.
            Thank you for booking my service, <b>{{$business}}</b>.
            I’m excited to work with you and will be in touch shortly with the next steps.”
        </p>
    </div>

    <div class="footer" style="margin-top: 2rem; color: gray; font-size: 12px;">
        <div style="display: flex; align-items: flex-start; margin-bottom: 1rem;">
            <img src="{{ $message->embed(config('app.api_base_url') . 'images/Email/EmailVertDark.png')}}"
                 alt="Company Logo"
                 style="height: 3rem; width: auto; margin-right: 1rem;">
            <div>
                <p style="font-weight: 600; margin: 0;">
                    If you have any questions, please contact us at support@tujitume.com.
                </p>
                <p style="margin: 0.5rem 0 0 0;">
                    Thank you for choosing Tujitume!<br/>
                    Best regards,<br/>
                    The Tujitume Team
                </p>
            </div>
        </div>
    </div>
</div>

<script
    src="https://code.jquery.com/jquery-3.4.1.min.js"
    integrity="sha256-CSXorXvZcTkaix6Yvo6HppcZGetbYMGWSFlBw8HfCJo="
    crossorigin="anonymous"
></script>

<script
    src="https://cdn.jsdelivr.net/npm/popper.js@1.16.0/dist/umd/popper.min.js"
    integrity="sha384-Q6E9RHvbIyZFJoft+2mJbHaEWldlvI9IOYy5n3zV9zzTtmI3UksdQRVvoxMfooAo"
    crossorigin="anonymous"
></script>
<script
    src="https://stackpath.bootstrapcdn.com/bootstrap/4.4.1/js/bootstrap.min.js"
    integrity="sha384-wfSDF2E50Y2D1uUdj0O3uMBJnjuUD4Ih7YwaYd1iqfktj0Uod8GCExl3Og8ifwB6"
    crossorigin="anonymous"
></script>

<!--Hidden Cart view-->
