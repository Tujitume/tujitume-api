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
            src="{{ $message->embed('https://tujitume.com/images/Email/EmailWhite.png')}}"
            alt="Company Logo"
            style="height: 3rem; width: auto; margin: 0 auto"
        />
        <h1 style="font-size: 2rem; font-weight: 700; margin-top: 1rem">
            Request to invest with equipments
        </h1>
    </div>

    <div class="content" style="padding: 20px">
        <h2 style="text-align: left; color: black; font-family: sans-serif">
            <br />
            Request to invest with equipments
        </h2>

        <div style="width: 100%; margin: auto; padding: 16px">
            <div style="margin-top: 16px">
                <h4
                    style="
                        line-height: 1.75;
                        font-weight: 500;
                        color: rgba(0, 0, 0, 0.7);
                        font-family: sans-serif;
                        text-align: left;
                        font-size: 16px;
                    "
                >
                    Investor: <b>{{$inv_name}}</b>, Contact: ({{$inv_contact}})
                    <br />
                    wants to provide equipment for {{$mile_name}}, please
                    contact them to proceed. If you require a Transaction
                    Advisor, please click here
                </h4>
                <a
                    target="_blank"
                    href="<?php echo config('app.app_url');?>services"
                    style="
                        display: inline-block;
                        margin-top: 8px;
                        padding: 10px 20px;
                        font-size: 14px;
                        font-weight: 500;
                        color: #2f9f1f;
                        border: 1px solid #2f9f1f;
                        border-radius: 8px;
                        text-align: center;
                        text-decoration: none;
                        transition: all 0.3s ease;
                    "
                    onmouseover="this.style.backgroundColor='#388e3c'; this.style.color='white';"
                    onmouseout="this.style.backgroundColor=''; this.style.color='#2f9f1f';"
                    onfocus="this.style.boxShadow='0 0 0 4px rgba(72, 187, 120, 0.5)';"
                    onblur="this.style.boxShadow='';"
                >
                    Transaction Advisor
                </a>
            </div>
        </div>

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
                   <div style="margin-bottom:3px;" ">The Tujitume Team</div> 
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
