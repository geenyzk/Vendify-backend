<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Welcome to {{ site_name }}</title>
  <style>
    body { font-family: Arial, sans-serif; background-color: #f7f7f7; margin: 0; padding: 0; }
    .container { background-color: #fff; max-width: 600px; margin: 40px auto; padding: 20px; border-radius: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
    .header { color: #2c3e50; margin-bottom: 20px; }
    .footer { margin-top: 30px; font-size: 12px; color: #999; text-align: center; }
    .button { display: inline-block; padding: 10px 15px; margin-top: 20px; background-color: #28a745; color: #fff; text-decoration: none; border-radius: 5px; }
  </style>
</head>
<body>
  <div class="container">
    <h2 class="header">🎉 Welcome to {{ site_name }}, {{ first_name }}!</h2>
    <p>Thank you for registering on our VTU platform.</p>

    <p>You can now enjoy seamless airtime, data, and bill payments right at your fingertips.</p>

    <p>To get started, please verify your email address by clicking the button below:</p>

    <a href="{{ verification_url }}" class="button">Verify Email</a>

    <p>If you did not register, please ignore this email or contact support.</p>

    <div class="footer">
      <p>Need help? Contact support at <a href="mailto:support@yourvtusite.com">support@yourvtusite.com</a></p>
      <p>© {{ year }} {{ site_name }}.</p>
    </div>
  </div>
</body>
</html>
