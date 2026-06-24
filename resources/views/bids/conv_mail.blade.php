
<head>
	<link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.4.1/css/bootstrap.min.css">
</head>
        

<script src="https://cdn.tailwindcss.com"></script>
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
            Message Received
        </h1>
    </div>

        <div >
        <div class="content" style="padding: 20px;">
            <h2 class="email-title" style="font-size: 20px; margin-bottom: 20px;">Your Have Received A Message</h2>
           <p class="email-message" 
   style="font-size: 14px; padding-top: 10px; line-height: 1.6; margin-bottom: 30px; color: #333; font-family: Arial, sans-serif;">
    Hi, <strong>{{$sender}}</strong> sent you a message regarding the equipment verification process.
    <br><br>
    <span style="background-color: #f1f8e9; color: #256029; padding: 8px 12px; border-radius: 6px; display: inline-block; font-size: 14px; font-weight: 500; max-width: 250px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
        
    </span>
    <br><br>
    <a href="<?php echo config('app.app_url');?>dashboard/messages" 
       target="_blank"
       style="display: inline-block; background-color: #14532d; color: white; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-weight: 600; font-size: 14px; transition: background-color 0.3s ease-in-out;"
       onmouseover="this.style.backgroundColor='#139647';"
       onmouseout="this.style.backgroundColor='#14532d';">
        View Message
    </a>
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
    </div>


<!--Hidden Cart view-->

         
  

             
      

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