<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8" />
  <title>Password Reset Request</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      background-color: #f7f7f7;
      margin: 0; padding: 0;
    }
    .container {
      background-color: #fff;
      max-width: 600px;
      margin: 40px auto;
      padding: 20px;
      border-radius: 10px;
      box-shadow: 0 4px 10px rgba(0,0,0,0.1);
    }
    .header {
      color: #2c3e50;
      margin-bottom: 20px;
    }
    .button {
      display: inline-block;
      padding: 10px 15px;
      margin-top: 20px;
      background-color: #dc3545;
      color: #fff;
      text-decoration: none;
      border-radius: 5px;
    }
    .footer {
      margin-top: 30px;
      font-size: 12px;
      color: #999;
      text-align: center;
    }
  </style>
</head>
<body>
  <div class="container">
    <h2 class="header">🔑 Password Reset Request</h2>
    <p>Hello {{ first_name }},</p>
    <p>We received a request to reset the password for your VTU account.</p>

    <p>Click the button below to reset your password:</p>
    <a href="{{ reset_url }}" class="button">Reset Password</a>

    <p>If you did not request a password reset, please ignore this email or contact support immediately.</p>

    <div class="footer">
      <p>Need help? Contact support at <a href="mailto:support@yourvtusite.com">support@yourvtusite.com</a></p>
      <p>© {{ year }} {{ site_name }}.</p>
    </div>
  </div>
</body>
</html>
