<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Complete Registration</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border: 1px solid #dddddd;
            padding: 20px;
        }
        .header {
            background-color: #14532d;
            color: #ffffff;
            padding: 10px;
            text-align: center;
            font-size: 20px;
            font-weight: bold;
        }
        .content {
            margin: 20px 0;
            color: #333333;
            font-size: 14px;
            text-align: center;
        }
        a.button {
            display: inline-block;
            background-color: #28a745;
            color: #ffffff !important;
            text-decoration: none;
            padding: 10px 15px;
            border-radius: 5px;
            margin-top: 10px;
        }
        .footer {
            font-size: 12px;
            color: #999999;
            text-align: center;
            margin-top: 20px;
            border-top: 1px solid #dddddd;
            padding-top: 10px;
        }
        .image {
            height: auto;
            width: 100%;
            border-radius: 1%;
            margin: 10px auto;
        }
    </style>
</head>
<body>
<div class="email-container">
    <div class="header">
        Complete Registration
    </div>
    <div class="content">
        <p>Please go to <a href="https://beta.tujitume.com">beta.tujitume.com</a> and complete registration as a business owner by clicking <strong>'Sign In'</strong>, then you can create your own business.</p>
        <p><a href="https://beta.tujitume.com?registerModal=open" class="button">Sign Up</a></p>
        <img
            src="{{ $message->embed(config('app.api_base_url') . 'images/Email/register.png')}}"
            alt="registration"
            class="image"
        />
    </div>
    <div class="footer">
        &copy; 2025 Tujitume. All rights reserved.
    </div>
</div>
</body>
</html>
