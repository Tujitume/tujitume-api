
<head>
	<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.4.1/css/bootstrap.min.css">
</head>


<!--Hidden Cart view-->


    <!-- equipments release -->
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
        <h1
            style="font-size: 2rem; font-weight: 700; margin-top: 1rem"
        >
            Equipment Release Request
        </h1>
    </div>
     <div class="content" style="padding: 20px;">
            <h2 style="font-size: 20px; margin-bottom: 20px;">Equipment Release</h2>
            <p class="email-message" style="font-size: 12px; padding-top: 10px; line-height: 1.8; margin-bottom: 30px;">Hi, Your bid is now verified, you can now Proceed to release the equipment.</p>
            <p class="email-message" style="font-size: 12px; padding-top: 10px; line-height: 1.8; margin-bottom: 30px;">Please be on alert of completion milestone emails as progress of their investment depends on your review.</p>

            <div class="button-container" style="display: flex; margin-top: 20px;">
                @if( !$manager || $manager == '' )
                    <a target="_blank" href="<?php echo config('app.app_url');?>equipmentRelease/{{$business_owner}}/0/{{$bid_id}}" class="button" style="display: inline-block; padding: 12px 24px; text-decoration: none; color: #fff; border-radius: 6px; transition: background-color 0.3s ease; background-color: green;">Proceed to Release Equipment.</a>
                @else
                    <a target="_blank" href="<?php echo config('app.app_url');?>equipmentRelease/{{$business_owner}}/{{$manager}}/{{$bid_id}}" class="button" style="display: inline-block; padding: 12px 24px; text-decoration: none; color: #fff; border-radius: 6px; transition: background-color 0.3s ease; background-color: green;">Proceed to Release Equipment.</a>
                @endif

                &nbsp; <a target="_blank" href="<?php echo config('app.api_url');?>CancelEquipmentRelease/{{$bid_id}}/confirm" class="button" style="
                        color: #e11d48;
                        border: 1px solid #e11d48;
                        padding: 0.625rem 1.25rem;
                        font-size: 0.875rem;
                        font-weight: 500;
                        border-radius: 0.5rem;
                        text-align: center;
                        margin-right: 0.5rem;
                        margin-bottom: 0.5rem;
                        text-decoration: none;
                    "
                    onmouseover="this.style.backgroundColor='#9f1239'; this.style.color='white'; this.style.textDecoration='none';"
                    onmouseout="this.style.backgroundColor=''; this.style.color='#e11d48';"
                    onfocus="this.style.boxShadow='0 0 0 4px rgba(248, 113, 113, 0.5)';"
                    onblur="this.style.boxShadow='';">Cancel.</a>
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



<script src="https://code.jquery.com/jquery-3.4.1.min.js" integrity="sha256-CSXorXvZcTkaix6Yvo6HppcZGetbYMGWSFlBw8HfCJo=" crossorigin="anonymous"></script>


<script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.0/dist/umd/popper.min.js" integrity="sha384-Q6E9RHvbIyZFJoft+2mJbHaEWldlvI9IOYy5n3zV9zzTtmI3UksdQRVvoxMfooAo" crossorigin="anonymous"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.4.1/js/bootstrap.min.js" integrity="sha384-wfSDF2E50Y2D1uUdj0O3uMBJnjuUD4Ih7YwaYd1iqfktj0Uod8GCExl3Og8ifwB6" crossorigin="anonymous"></script>

    <script type="text/javascript">
    	function openModal(val) {
		if(val == 'hide')
		$('#ConfirmModal').css('display','none');
		else
    	$('#ConfirmModal').css('display','block');
    	}
    </script>


<!--Hidden Cart view-->
