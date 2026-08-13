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
            Bid Approval Reminder for {{$business}}
        </h1>
    </div>

<div style="padding: 30px 20px;">
    <h3 style="
        color: #2f9f1f;
        font-family: Arial, sans-serif;
        font-size: 24px;
        font-weight: 600;
        margin-bottom: 20px;
        line-height: 1.4;
    ">  
         Dear {{$owner}},
    </h3>

    <div style="margin-bottom: 25px;">
        <p style="
            color: #333;
            font-family: Arial, sans-serif;
            font-size: 16px;
            font-weight: 400;
            margin-bottom: 15px;
            line-height: 1.6;
        ">
            Hi, you have pending bids awaiting action on your dashboard. Please review the pending bids.
        </p>
        
        <p style="
            color: #333;
            font-family: Arial, sans-serif;
            font-size: 16px;
            font-weight: 500;
            margin-bottom: 20px;
            line-height: 1.6;
        ">
            <strong>Business Name:</strong> {{$business}}
        </p>
        
        <div style="
            background-color: #fff3cd; 
            color: #856404; 
            font-weight: 500; 
            padding: 15px 20px; 
            border-radius: 8px;
            margin: 20px 0;
            line-height: 1.6;
            border-left: 4px solid #ffc107;
        ">
            <strong>Important Notice:</strong> If no action is taken within <strong>30 days</strong>, bids will be automatically cancelled as per Tujitume policy.
        </div>

        <div style="margin-top: 30px; text-align: center;">
            <a target="_blank" href="<?php echo config('app.app_url');?>dashboard/investment-bids"
                style="
                    display: inline-block;
                    background-color: #2f9f1f;
                    color: white;
                    border: none;
                    padding: 15px 30px;
                    font-size: 16px;
                    font-weight: 600;
                    border-radius: 8px;
                    text-decoration: none;
                    transition: all 0.3s ease;
                    box-shadow: 0 2px 4px rgba(47, 159, 31, 0.2);
                "
                onmouseover="this.style.backgroundColor='#25821b'; this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 8px rgba(47, 159, 31, 0.3)';"
                onmouseout="this.style.backgroundColor='#2f9f1f'; this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 4px rgba(47, 159, 31, 0.2)';"
            >
                Review Bids
            </a>
        </div>
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
  
        
        
       
      

<script src="https://code.jquery.com/jquery-3.4.1.min.js" integrity="sha256-CSXorXvZcTkaix6Yvo6HppcZGetbYMGWSFlBw8HfCJo=" crossorigin="anonymous"></script>
       

<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.0/dist/umd/popper.min.js" integrity="sha384-Q6E9RHvbIyZFJoft+2mJbHaEWldlvI9IOYy5n3zV9zzTtmI3UksdQRVvoxMfooAo" crossorigin="anonymous"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.4.1/js/bootstrap.min.js" integrity="sha384-wfSDF2E50Y2D1uUdj0O3uMBJnjuUD4Ih7YwaYd1iqfktj0Uod8GCExl3Og8ifwB6" crossorigin="anonymous"></script>
<!--Hidden Cart view-->