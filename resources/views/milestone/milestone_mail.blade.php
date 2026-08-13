<!--Hidden Cart view-->

<div style="
        max-width: 1024px;
        margin-left: auto;
        margin-right: auto;
        margin-top: 4rem;
        background-color: white;
        border-radius: 0.5rem;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        overflow: hidden;
        position: relative;
    ">
    <!-- Header with Logo -->
    <div style="
            background-color: #14532d;
            padding: 0.9rem 0;
            text-align: center;
            color: #ffffff;
            position: relative;
            z-index: 10;
        ">
        <img src="{{ $message->embed(config('app.api_base_url') . 'images/Email/EmailWhite.png')}}" alt="Company Logo"
            style="height: 3rem; width: auto; margin: 0 auto" />
        <h1 style="font-size: 2rem; font-weight: 700; margin-top: 1rem">
            Milestone Status Changed to Done
        </h1>
    </div>

    <div style="padding: 30px 20px; font-family: Arial, sans-serif;">
        <h2 style="
            color: #2f9f1f; 
            font-size: 24px; 
            font-weight: 600; 
            margin-bottom: 25px;
            text-align: center;
        ">
            Milestone Status Changed to Done
        </h2>

        <div style="
            background: #f8f9fa; 
            padding: 25px; 
            border-radius: 8px; 
            border-left: 4px solid #2f9f1f;
            margin: 20px 0;
        ">
            <p style="
                font-weight: 500; 
                color: #333; 
                font-size: 16px; 
                margin-bottom: 12px;
                line-height: 1.5;
            ">
                <strong>Milestone Name:</strong> {{$name}}
            </p>
            <p style="
                font-weight: 500; 
                color: #333; 
                font-size: 16px; 
                margin-bottom: 12px;
                line-height: 1.5;
            ">
                <strong>Amount:</strong> {{$amount}}
            </p>
            <p style="
                font-weight: 500; 
                color: #333; 
                font-size: 16px;
                margin-bottom: 0;
                line-height: 1.5;
            ">
                <strong>Service Name:</strong> {{$business}}
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

</div>

<script
    src=" https://code.jquery.com/jquery-3.4.1.min.js" integrity="sha256-CSXorXvZcTkaix6Yvo6HppcZGetbYMGWSFlBw8HfCJo="
                crossorigin="anonymous">
                </script>

                <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.0/dist/umd/popper.min.js"
                    integrity="sha384-Q6E9RHvbIyZFJoft+2mJbHaEWldlvI9IOYy5n3zV9zzTtmI3UksdQRVvoxMfooAo"
                    crossorigin="anonymous"></script>
                <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.4.1/js/bootstrap.min.js"
                    integrity="sha384-wfSDF2E50Y2D1uUdj0O3uMBJnjuUD4Ih7YwaYd1iqfktj0Uod8GCExl3Og8ifwB6"
                    crossorigin="anonymous"></script>

                <!--Hidden Cart view-->