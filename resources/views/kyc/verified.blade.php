<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>KYC verification complete</title>
</head>
<body style="margin: 0; padding: 24px; background: #f4f6f4; color: #26332b; font-family: Arial, sans-serif;">
    <main style="max-width: 600px; margin: 0 auto; padding: 32px; background: #ffffff; border-top: 4px solid #14532d;">
        <p style="margin: 0 0 24px; color: #14532d; font-size: 14px; font-weight: 700;">TUJITUME</p>
        <h1 style="margin: 0 0 20px; font-size: 24px;">Your KYC is verified</h1>
        <p>Hello {{ $name }},</p>
        <p>Your {{ strtolower($verificationType) }} verification has been approved. Your KYC status is now verified.</p>
        <p>You can continue using Tujitume with your verified account.</p>
        <p style="margin-top: 32px;">Regards,<br>The Tujitume Team</p>
    </main>
</body>
</html>