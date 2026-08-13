<!--Hidden Cart view-->

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
        <h1 style="font-size: 2rem; font-weight: 700; margin-top: 1rem">
            Tujitume Join Request
        </h1>
    </div>
    <div class="content" style="padding: 20px">
        <h2 style="color: green; font-family: sans-serif">
			A request to manage a {{$org}} as ({{ ucfirst($role) }}) was sent to you.
        </h2>

        <p style="text-align: left">
            Hi, you have been requested to manage a {{$org}} from {{$o_email}} at beta.tujitume.com, please accept
            this invitation by clicking the link below to create your new password. After that you can login with this email
            ({{$email}}) and new password
        </p>

        <a href="<?php echo config('app.app_url');?>create-password?jmru_Eid={{base64_encode($email)}}"
            style=" display:inline-block;margin-top:15px;margin-bottom:15px;color:white; background:seagreen;
		    font-family:monospace; font-weight:700;border:1px solid #2f9f1f;padding:0.625rem 2.25rem;font-size:0.875rem;border-radius:0.5rem;text-align:center;text-decoration:none" target="_blank" >
            Accept
        </a>

{{--        <a href="{{$link}}"--}}
{{--           style=" display:inline-block;margin-top:15px;margin-bottom:15px;margin-left:10px;color:white; background:goldenrod;--}}
{{--		    font-family:monospace; font-weight:700;border:1px solid #2f9f1f;padding:0.625rem 2.25rem;font-size:0.875rem;border-radius:0.5rem;text-align:center;text-decoration:none" target="_blank" >--}}
{{--            Reject & Create Your Own Account--}}
{{--        </a>--}}



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
                        src="{{ $message->embed(config('app.api_base_url') . 'images/Email/EmailVertDark.png')}}"
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
