<!--Hidden Cart view-->
<!-- import tailwind css -->
<script src="https://cdn.tailwindcss.com"></script>

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
                    Equipment Release Cancel Confirmation
                </h1>
            </div>
        </div>
        <!-- Bid Rejected -->
        <div class="email-container">
            <div class="content" style="padding: 20px">
                <p
                    class="email-message"
                    style="
                        font-size: 12px;
                        line-height: 1.8;
                        margin-bottom: 30px;
                    "
                >
                    Hi,<br />Are you sure about cancelling this equipment release? With 'Ok',
                    your commitment from Investment to business
                    {{$business_name}} will be canceled & you'll be redirected
                    to Tujitume.
                </p>

                <div class="w-full mx-auto mt-4 py-4 text-center">
                    <a
                        href="<?php echo config('app.api_url');?>CancelAssetBid/{{$bid_id}}/ok"
                        style="
                        color: #2f9f1f;
                        border: 1px solid #2f9f1f;
                        padding: 0.625rem 1.25rem;
                        font-size: 0.875rem;
                        font-weight: 500;
                        border-radius: 0.5rem;
                        text-align: center;
                        margin-right: 0.5rem;
                        margin-bottom: 0.5rem;
                        text-decoration: none;
                    "
                    onmouseover="this.style.backgroundColor='#388e3c'; this.style.color='white'; this.style.textDecoration='none';"
                    onmouseout="this.style.backgroundColor=''; this.style.color='#2f9f1f';"
                    onfocus="this.style.boxShadow='0 0 0 4px rgba(72, 187, 120, 0.5)';"
                    onblur="this.style.boxShadow='';"
                    >
                        OK</a
                    >

                    &nbsp;<a
                        target="_blank"
                        href="<?php echo config('app.app_url');?>equipmentRelease/{{$business_owner}}/{{$manager}}/{{$bid_id}}"
                        style="
                            background-color: #14532d;
                            color: white;
                            padding: 0.75rem 1.5rem;
                            border-radius: 0.5rem;
                            text-decoration: none;
                            font-weight: 500;
                            font-size: 1rem;
                            transition: background-color 0.3s ease-in-out;
                        "
                        onmouseover="this.style.backgroundColor='#139647';"
                        onmouseout="this.style.backgroundColor='#14532d';"
                        target="_blank"
                    >
                        Release Equipment</a
                    >

                    
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
                   <div style="margin-bottom:3px;" >The Tujitume Team</div> 
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
</div>
