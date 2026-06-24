<!--Hidden Cart view-->
<!-- import tailwind css -->
<script src="https://cdn.tailwindcss.com"></script>

<!-- Header with Logo -->
<div
    class="max-w-4xl mx-auto mt-16 bg-white rounded-lg shadow-lg overflow-hidden relative"
>
    <div>
        <div
        class="bg-green-900 py-10 text-center text-white relative z-10"
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
            class="h-12 w-auto mx-auto"
            style="height: 3rem; width: auto; margin: 0 auto"
        />
        <h1
            class="text-3xl font-bold mt-4"
            style="font-size: 2rem; font-weight: 700; margin-top: 1rem"
        >
            Equipment Verify request
        </h1>
    </div>
    </div>
    <!-- Bid Rejected -->
    <div class="email-container">
        <div class="content" style="padding: 20px">
            <h2
                class="email-title"
                style="font-size: 20px; margin-bottom: 20px"
            >

            </h2>
            <p
                class="email-message"
                style="font-size: 12px; line-height: 1.8; margin-bottom: 30px"
            >
                Hi,<br />{{$investor}} requested you to verify their Equipment regarding to a bid to business {{$business_name}}. Please goto dashboard notifications to review.
            </p>

            <a
                target="_blank"
                href="<?php echo config('app.app_url');?>dashboard"
                style="color: #2f8555; border: 1px solid #2f8555; padding: 0.625rem 1.25rem; font-size: 0.875rem; font-weight: 500; border-radius: 0.5rem; text-align: center; margin-right: 0.5rem; margin-bottom: 0.5rem; text-decoration: none; transition: background-color 0.3s, color 0.3s; outline: none;"
    onmouseover="this.style.backgroundColor='#38a169'; this.style.color='white';"
    onmouseout="this.style.backgroundColor=''; this.style.color='#2f8555';"
    onfocus="this.style.boxShadow='0 0 0 4px rgba(72, 187, 120, 0.5)';"
    onblur="this.style.boxShadow='';"
>
                Review</a
            >
<div
                class="footer"
                style="
                    margin-top: 2rem;
                    text-align: start;
                    color: gray;
                    font-size: 12px;
                "
            >
                <p>
                    <img
                        src="{{ $message->embed('https://tujitume.com/images/Email/EmailVertDark.png')}}"
                        alt="Company Logo"
                        style="
                            height: 3rem;
                            width: auto;
                            float: left;
                            margin-right: 1rem;
                            margin-top: -0.2rem;
                            margin-bottom: 4rem;
                        "
                    />
                </p>
                 <p style="font-weight: 600">
                    Best regards, <br/>
                   <div style="margin-bottom:3px;">The Tujitume Team</div>
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
