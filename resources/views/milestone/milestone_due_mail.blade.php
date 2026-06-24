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
            Milestone Due Alert
        </h1>
    </div>

    <div class="email-container">
        <div
            class="content"
            style="padding: 20px; text-align: center; font-family: sans-serif"
        >
            <h2
                class="email-title"
                style="font-size: 1.25rem; margin-bottom: 15px; color: #333"
            >
                Your milestone has {{$d}} day(s) left
            </h2>

            <div
                style="
                    width: 60%;
                    margin: auto;
                    text-align: left;
                    background: #f9f9f9;
                    padding: 15px;
                    border-radius: 8px;
                "
            >
                <p
                    class="email-message"
                    style="
                        font-size: 0.875rem;
                        padding-top: 5px;
                        line-height: 1.5;
                        margin-bottom: 10px;
                        color: #555;
                    "
                >
                    <strong>Milestone Name:</strong> {{$name}}
                </p>
                <h4 style="color: rgb(0, 0, 0); font-size: 1rem; margin-bottom: 5px">
                    <strong>Amount:</strong> <span style="color: #14532d">${{$amount}}</span>
                </h4>
            </div>
        </div>

         <div
                class="footer"
                style="
                    margin-top: 2rem;
                    text-align: start;
                    color: gray;
                    font-size: 12px;
					padding-left: 20px
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
