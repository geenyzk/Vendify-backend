<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Email</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            padding: 40px;
            max-width: 400px;
            width: 100%;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        .header h1 {
            color: #333;
            font-size: 28px;
            margin-bottom: 10px;
        }

        .header p {
            color: #666;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
            font-size: 14px;
        }

        input[type="text"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
            font-weight: 600;
            letter-spacing: 2px;
            text-align: center;
            transition: border-color 0.3s;
        }

        input[type="text"]:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        input[type="text"]::placeholder {
            letter-spacing: normal;
        }

        .button-group {
            margin-top: 30px;
        }

        button {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }

        button:active {
            transform: translateY(0);
        }

        button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .resend-link {
            text-align: center;
            margin-top: 20px;
        }

        .resend-link a {
            color: #667eea;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.2s;
        }

        .resend-link a:hover {
            color: #764ba2;
            text-decoration: underline;
        }

        .error-message {
            color: #dc3545;
            font-size: 14px;
            margin-top: 10px;
            text-align: center;
            display: none;
        }

        .success-message {
            color: #28a745;
            font-size: 14px;
            margin-top: 10px;
            text-align: center;
            display: none;
        }

        .loading {
            display: none;
            text-align: center;
            margin-top: 10px;
        }

        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1>Verify Your Email</h1>
            <p>Enter the 6-digit code sent to your email address</p>
        </div>

        <form id="verifyForm">
            @csrf
            <div class="form-group">
                <label for="code">Verification Code</label>
                <input type="text" id="code" name="code" placeholder="000000" maxlength="6"
                    pattern="[0-9]{6}" inputmode="numeric" required autocomplete="off">
            </div>

            <div class="error-message" id="errorMessage"></div>
            <div class="success-message" id="successMessage"></div>

            <div class="loading" id="loading">
                <div class="spinner"></div>
                <p>Verifying...</p>
            </div>

            <div class="button-group">
                <button type="submit" id="submitBtn">Verify Email</button>
            </div>
        </form>

        <div class="resend-link">
            <p>Didn't receive the code? <a href="{{ route('verification.send') }}"
                    onclick="handleResend(event)">Resend</a></p>
        </div>
    </div>

    <script>
        const form = document.getElementById('verifyForm');
        const codeInput = document.getElementById('code');
        const submitBtn = document.getElementById('submitBtn');
        const errorMessage = document.getElementById('errorMessage');
        const successMessage = document.getElementById('successMessage');
        const loading = document.getElementById('loading');

        // Auto format input to numbers only
        codeInput.addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '');
        });

        // Handle form submission
        form.addEventListener('submit', async function(e) {
            e.preventDefault();

            const code = codeInput.value.trim();

            if (code.length !== 6) {
                showError('Please enter a valid 6-digit code');
                return;
            }

            submitBtn.disabled = true;
            loading.style.display = 'block';
            errorMessage.style.display = 'none';
            successMessage.style.display = 'none';

            try {
                const response = await fetch('{{ route('verification.code') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('input[name="_token"]').value
                    },
                    body: JSON.stringify({
                        code: code
                    })
                });

                const data = await response.json();

                if (response.ok && data.success) {
                    showSuccess('Email verified successfully! Redirecting...');
                    // Redirect to frontend after 2 seconds
                    setTimeout(() => {
                        const frontendUrl = new URL(
                            '{{ env('FRONTEND_URL', 'http://localhost:5173') }}');
                        window.location.href = frontendUrl.origin + '/email-verified?verified=1';
                    }, 2000);
                } else {
                    showError(data.message || 'Failed to verify email. Please try again.');
                    submitBtn.disabled = false;
                }
            } catch (error) {
                console.error('Error:', error);
                showError('An error occurred. Please try again.');
                submitBtn.disabled = false;
            } finally {
                loading.style.display = 'none';
            }
        });

        function showError(message) {
            errorMessage.textContent = message;
            errorMessage.style.display = 'block';
        }

        function showSuccess(message) {
            successMessage.textContent = message;
            successMessage.style.display = 'block';
        }

        async function handleResend(e) {
            e.preventDefault();

            const link = e.target;
            const originalText = link.textContent;
            link.textContent = 'Sending...';
            link.style.pointerEvents = 'none';

            try {
                const response = await fetch('{{ route('verification.send') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('input[name="_token"]').value
                    }
                });

                if (response.ok) {
                    showSuccess('Code resent! Check your email.');
                    setTimeout(() => {
                        link.textContent = originalText;
                        link.style.pointerEvents = 'auto';
                        successMessage.style.display = 'none';
                    }, 3000);
                } else {
                    showError('Failed to resend code. Please try again.');
                    link.textContent = originalText;
                    link.style.pointerEvents = 'auto';
                }
            } catch (error) {
                console.error('Error:', error);
                showError('An error occurred. Please try again.');
                link.textContent = originalText;
                link.style.pointerEvents = 'auto';
            }
        }

        // Focus on input when page loads
        window.addEventListener('load', () => {
            codeInput.focus();
        });
    </script>
</body>

</html>
