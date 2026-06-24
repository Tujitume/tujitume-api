

        

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
            Final Milestone Completed for {{$business}}
        </h1>
    </div>
 <div class="content" style="padding: 20px">
	<h2 style="color: green; font-size: 1.8rem; font-weight: bold; margin-bottom: 15px;">
      Dear Customer,</h2>
			
			<div class="" style="">
			 <h4 style="color: black; font-size: 1rem; font-weight: 500; line-height: 1.5;">
            All milestones for the service {{$business}} have been completed by the service
owner.
        </h4>

        <p style="
        margin-top: 20px;
        font-size: 16px;
        color:black;
        
    ">
        Next Steps:<br>
Please confirm if you would like to release the final payment. Once confirmed, the
remaining payment will be transferred to the service owner.
    </p>
<div style="
    background-color: #fff3cd; 
    color: #856404; 
    font-weight: bold; 
    padding: 10px; 
    border-radius: 5px;
    margin-bottom: 10px;
    line-height: 1.5;
">
    If no action is taken within <strong>7 days</strong> from the date of this email, the final payment will
    be automatically released to the service.
</div>

<div style="margin-top: 10px;">
    <!-- <a href="#" style="
        background-color: #28a745; 
        color: white; 
        font-weight: bold; 
        padding: 10px 15px; 
        border-radius: 5px; 
        text-decoration: none;
        display: inline-block;
        margin-right: 8px;
    ">
        RELEASE FINAL PAYMENT
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
            Release final payment
        </a>


         @php $s_id = base64_encode(base64_encode($s_id)) @endphp
        <a target="_blank" href="<?php echo config('app.app_url');?>service-details/{{$s_id}}?review_popup=true" class="button continue" style="display: inline-block; padding: 12px 24px; text-decoration: none; color: #fff; border-radius: 6px; transition: background-color 0.3s ease; background-color: green;">Review Service</a>
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
                   <div style="margin-bottom:3px;">The Tujitume Team</div> 
                </p>
            </div>

		</div>
</div>

		
		
		</div>
  
        
		
       
      

<script src="https://code.jquery.com/jquery-3.4.1.min.js" integrity="sha256-CSXorXvZcTkaix6Yvo6HppcZGetbYMGWSFlBw8HfCJo=" crossorigin="anonymous"></script>
       

<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.0/dist/umd/popper.min.js" integrity="sha384-Q6E9RHvbIyZFJoft+2mJbHaEWldlvI9IOYy5n3zV9zzTtmI3UksdQRVvoxMfooAo" crossorigin="anonymous"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.4.1/js/bootstrap.min.js" integrity="sha384-wfSDF2E50Y2D1uUdj0O3uMBJnjuUD4Ih7YwaYd1iqfktj0Uod8GCExl3Og8ifwB6" crossorigin="anonymous"></script>
    


<!--Hidden Cart view-->


        

        
