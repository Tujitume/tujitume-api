<!--Hidden Cart view-->

<!-- Header with Logo -->
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
    <div>
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
            src="{{ $message->embed('https://tujitume.com/images/Email/EmailWhite.png')}}"
            alt="Company Logo"
            style="height: 3rem; width: auto; margin: 0 auto"
        />
        <h1 style="font-size: 2rem; font-weight: 700; margin-top: 1rem">
            Bid Cancelled
        </h1>
    </div>
    </div>
    <div style="padding: 30px 20px;">
        <h2 style="
            font-size: 24px; 
            margin-bottom: 25px;
            color: #e11d48;
            font-weight: 600;
            font-family: Arial, sans-serif;
        ">
            Unfortunately, Your Bid Has Been Cancelled
        </h2>
        <p style="
            font-size: 16px; 
            line-height: 1.6; 
            margin-bottom: 25px;
            color: #333;
            font-family: Arial, sans-serif;
        ">
            Hi,<br/>The Investor <strong>{{$investor}}</strong> has decided to rescind their commitment from Investment to business <strong>{{$business_name}}</strong>.
        </p>
        <div style="
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #e5e5e5;
            text-align: left;
            color: #666;
            font-size: 14px;
        ">
            <img
                src="{{ $message->embed('https://tujitume.com/images/Email/EmailVertDark.png')}}"
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
