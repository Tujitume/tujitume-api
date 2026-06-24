

        

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
			Milestone Reminder for {{$name}}
        </h1>
    </div>

<div class="content" style="padding: 20px">
    <h3 style="
        color: #2f9f1f;
        font-family: sans-serif;
        font-size: 22px;
        font-weight: 600;
        margin-bottom: 16px;
    ">  
        <!-- Milestone {{$name}} is Completed!   -->
         Dear Customer,
    </h3>

   <div style="
    ">
    <h5 style="
        color: #333;
        font-family: sans-serif;
        font-size: 16px;
        font-weight: 500;
        margin-bottom: 5px;
        line-height: 1.3;
    ">
        The Milestone payment for {{$name}} of the service {{$business}} will be automatically released in @php echo 7-$d; @endphp, if you do not proceed to continue.
    </h5>
    <h5 style="
        color: #333;
        font-family: sans-serif;
        font-size: 16px;
        font-weight: 500;
        margin-bottom: 5px;
        line-height: 1.3;
    ">
        Milestone Details: <br> Milestone: {{$name}} <br> 
        Status: <span style="
            background-color: #d4edda; 
            color: #155724; 
            font-weight: bold;
            padding: 2px 8px; 
            border-radius: 15px;
            display: inline-block;
            margin-top: 5px;
            padding-left: 10px;
        ">Completed</span>
    </h5>
    <h5 style="
        color: #333;
        font-family: sans-serif;
        font-size: 16px;
        font-weight: 500;
        margin-bottom: 0;
        line-height: 1.3;
        margin-bottom: 10px;
    ">
        <!-- Business Name: <b>{{$business}}</b> -->
         Next Steps:<br>Please confirm if you would like to proceed to the next milestone. Once confirmed, the
payment for this milestone will be released to the service owner.
    </h5>
    <div style="
    background-color: #fff3cd; 
    color: #856404; 
    font-weight: bold; 
    padding: 10px; 
    border-radius: 5px;
    margin-bottom: 10px;
    line-height: 1.5;
">
    If no action is taken within <strong> @php echo 7-$d; @endphp  days</strong> 
    milestone will be automatically released to the service owner as per our auto-approval
    policy.<br><br>

    If you have any concerns about the milestone, please <strong>contact us</strong> before the auto-approval 
    period ends.
</div>

<div style="margin-top: 10px;">
<!--    <a href="#" style="
        background-color: #28a745; 
        color: white; 
        font-weight: bold; 
        padding: 10px 15px; 
        border-radius: 5px; 
        text-decoration: none;
        display: inline-block;
        margin-right: 8px;
        ">
        CONFIRM AND PROCEED
    </a> -->

    @php $random = $rep_id + 51;
    $random2 = $booker_id + 47;

    $rep_id = base64_encode($rep_id.'.'.$random);
    $booker_id = base64_encode($booker_id.'.'.$random2);
    @endphp

        <a target="_blank" href="<?php echo config('app.api_url');?>agreeToMileS/{{$rep_id}}/{{$booker_id}}"
            style="
                display: inline-block;
                background-color: #2f9f1f;
                color: white;
                border: none;
                padding: 12px 24px;
                font-size: 14px;
                font-weight: 500;
                border-radius: 8px;
                text-decoration: none;
                transition: all 0.3s ease;
            "
            onmouseover="this.style.backgroundColor='#1f7f14';"
            onmouseout="this.style.backgroundColor='#2f9f1f';"
        >
            CONFIRM AND PROCEED
        </a>


    <!-- <a href="#" style="
        background-color: #007bff; 
        color: white; 
        font-weight: bold; 
        padding: 10px 15px; 
        border-radius: 5px; 
        text-decoration: none;
        display: inline-block;
    ">
        REVIEW MILESTONE
    </a> -->
</div>

</div>


    <p style="
        margin-top: 20px;
        font-size: 16px;
        color: #444;
    ">
        <!-- Do you want to proceed to the next milestone? -->
    </p>


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
  
        
		
       
      

<script src="https://code.jquery.com/jquery-3.4.1.min.js" integrity="sha256-CSXorXvZcTkaix6Yvo6HppcZGetbYMGWSFlBw8HfCJo=" crossorigin="anonymous"></script>
       

<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.0/dist/umd/popper.min.js" integrity="sha384-Q6E9RHvbIyZFJoft+2mJbHaEWldlvI9IOYy5n3zV9zzTtmI3UksdQRVvoxMfooAo" crossorigin="anonymous"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.4.1/js/bootstrap.min.js" integrity="sha384-wfSDF2E50Y2D1uUdj0O3uMBJnjuUD4Ih7YwaYd1iqfktj0Uod8GCExl3Og8ifwB6" crossorigin="anonymous"></script>
    


<!--Hidden Cart view-->


        

        
