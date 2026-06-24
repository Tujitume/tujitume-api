<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>Milestone Completion</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body
        style="
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
        "
    >
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
            Milestone Completion
        </h1>
    </div>
            <!-- milestone section -->
            <div class="email-container">
                <div class="content" style="padding: 20px">
                    <h2 style="font-size: 20px; margin-bottom: 20px">
                        Completion of Milestone
                    </h2>
                    <p
                        class="email-message"
                        style="
                            font-size: 12px;
                            line-height: 1.8;
                            margin-bottom: 30px;
                        "
                    >
                        Hi,<br />
                        Your investment to the business {{$business_name}} is
                        done as all milestones are completed. Please review the
                        business.<br />
                    </p>
                    <div
                        class="button-container"
                        style="display: flex; margin-top: 20px"
                    >
                        <a
                            target="_blank"
                            href="<?php echo config('app.app_url');?>listing/{{$business_id}}?review_popup=true"
                             style="color: #2f8555; border: 1px solid #2f8555; padding: 0.625rem 1.25rem; font-size: 0.875rem; font-weight: 500; border-radius: 0.5rem; text-align: center; margin-right: 0.5rem; margin-bottom: 0.5rem; text-decoration: none; transition: background-color 0.3s, color 0.3s; outline: none;"
    onmouseover="this.style.backgroundColor='#38a169'; this.style.color='white';"
    onmouseout="this.style.backgroundColor=''; this.style.color='#2f8555';"
    onfocus="this.style.boxShadow='0 0 0 4px rgba(72, 187, 120, 0.5)';"
    onblur="this.style.boxShadow='';"
>Review</a
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

        <!-- milestone done -->

        <!-- <div class="email-container" style="width: 80%; margin: 50px auto; background-color: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
        <div class="content" style="padding: 20px;">
            <h2 class="email-title" style="font-size: 20px; margin-bottom: 20px;">Congratulations!</h2>
            <p class="email-message" style="font-size: 12px; padding-top: 10px; line-height: 1.8; margin-bottom: 30px;">All Milestones are completed and Thomas Muller thanks you for your business!</p>
            <p class="thanks" style="text-align: center; color: rgb(13, 14, 13); margin-top: 20px; font-size: 14px;">Thanks!<br>Jitume Admin</p>
        </div>
    </div> -->
    </body>
</html>
