# Gmail SMTP Configuration Audit

## 🔍 CRITICAL FINDINGS

### 1. **DEFAULT MAILER FALLBACK ISSUE** ⚠️ CRITICAL
**File:** [config/mail.php](config/mail.php#L16)

```php
'default' => env('MAIL_MAILER', 'log'),
```

**Problem:** If `MAIL_MAILER` is NOT set in `.env`, it defaults to `'log'` which means:
- Emails are **logged, not actually sent**
- This is a development safety feature, but it breaks production

**Solution:** Ensure `.env` on production has:
```
MAIL_MAILER=smtp
```

---

## 🔧 MAIL CONFIGURATION ARCHITECTURE

### Configuration Flow (in order of precedence):

1. **Database Settings** (HIGHEST PRIORITY - overrides everything)
   - [app/Providers/AppServiceProvider.php](app/Providers/AppServiceProvider.php#L70-L110)
   - Reads from `settings` table fields:
     - `mail_mailer`, `mail_host`, `mail_port`, `mail_username`, `mail_password`, `mail_encryption`, `mail_from_address`, `mail_from_name`

2. **Environment Variables** (.env)
   - [.env.example](.env.example#L67-L82)
   - Currently set to AWS SES SMTP (not Gmail!)

3. **Config File Defaults** (LOWEST PRIORITY)
   - [config/mail.php](config/mail.php)

### Database Configuration (Highest Priority):
[app/Models/Setting.php](app/Models/Setting.php#L1-L50) shows the fillable fields:

```php
'mail_mailer', 'mail_host', 'mail_port', 'mail_username',
'mail_password', 'mail_encryption', 'mail_from_address', 'mail_from_name'
```

**This means:** Admin panel Settings > Email tab settings OVERRIDE .env files!

---

## 🚨 ISSUES TO CHECK

### ✅ Issue #1: Is MAIL_MAILER set in production .env?
**Check:** Production `.env` file must have:
```
MAIL_MAILER=smtp
```

If it's set to `log` or missing → emails won't send

---

### ✅ Issue #2: Gmail SMTP Credentials in Database
**Check:** Admin Settings panel > Email tab
- Is `mail_host` set to: `smtp.gmail.com`
- Is `mail_port` set to: `587` (TLS) or `465` (SSL)
- Is `mail_username` set to your Gmail address or app password
- Is `mail_password` set correctly
- Is `mail_encryption` set to `tls` or `ssl`

**Note:** The `.env.example` shows AWS SES, not Gmail. But the code prioritizes the database settings.

---

### ✅ Issue #3: Queue Processing
**Current Setting:** [config/queue.php](config/queue.php#L23)
```php
'default' => env('QUEUE_CONNECTION', 'database'),
```

This means emails may be **queued to database**, not sent immediately.

**Check:**
1. Is a queue worker running? (`php artisan queue:work`)
2. Check `jobs` table - are there pending mail jobs?
3. Check `failed_jobs` table - are there failed email jobs?

**If NO queue worker is running:**
- Emails sit in the `jobs` table forever
- Never actually get sent

**Solution:** 
- Either start a queue worker: `php artisan queue:work`
- Or change to synchronous queue: `QUEUE_CONNECTION=sync`

---

### ✅ Issue #4: Email Testing
The app has a test email class: [app/Mail/TestSesMail.php](app/Mail/TestSesMail.php)

This mentions "Amazon SES SMTP" in the message, suggesting the previous setup was AWS SES.

**To test Gmail SMTP:**
1. Update admin Settings > Email with Gmail SMTP credentials
2. Run: `php artisan tinker`
3. Execute:
   ```php
   $user = User::first();
   $user->notify(new \App\Notifications\VendifyVerifyEmailNotification());
   ```
4. Check:
   - Did the email arrive?
   - If queued, did a queue worker process it?
   - Check logs: `storage/logs/laravel.log`

---

### ✅ Issue #5: Email Sending Flow
Emails are sent via Notifications:

**Main notification classes:**
- [app/Notifications/VendifyVerifyEmailNotification.php](app/Notifications/VendifyVerifyEmailNotification.php) - Email verification
- [app/Notifications/VendifyResetPasswordNotification.php](app/Notifications/VendifyResetPasswordNotification.php) - Password reset
- [app/Notifications/AppNotification.php](app/Notifications/AppNotification.php) - In-app only (NOT emailed)
- [app/Notifications/BroadcastNotification.php](app/Notifications/BroadcastNotification.php) - Queued notifications
- [app/Notifications/MigratedAccountInvite.php](app/Notifications/MigratedAccountInvite.php)

**Admin notifications:**
- [app/Mail/AdminNotificationMail.php](app/Mail/AdminNotificationMail.php) - Mailable class

---

## 📋 CHECKLIST: Gmail SMTP Setup

- [ ] **1. Database Settings**: Check admin Settings > Email tab
  - [ ] `mail_mailer` = `smtp`
  - [ ] `mail_host` = `smtp.gmail.com`
  - [ ] `mail_port` = `587` or `465`
  - [ ] `mail_username` = Gmail address or app password
  - [ ] `mail_password` = Correct Gmail app password (NOT regular password)
  - [ ] `mail_encryption` = `tls` or `ssl`
  - [ ] `mail_from_address` = Valid sender email
  - [ ] `mail_from_name` = Sender name

- [ ] **2. Environment Variables**: Check production `.env`
  - [ ] `MAIL_MAILER=smtp` is set
  - [ ] Not set to `log` or missing

- [ ] **3. Queue Configuration**: 
  - [ ] If using database queue: is queue worker running?
  - [ ] Run: `php artisan queue:work`
  - [ ] Or switch to sync: `QUEUE_CONNECTION=sync`

- [ ] **4. Test Email Sending**:
  - [ ] Send a test notification
  - [ ] Check logs: `storage/logs/laravel.log`
  - [ ] Check email arrives in inbox (check spam!)
  - [ ] Check `jobs` table is empty (queued jobs processed)

- [ ] **5. Gmail Account Settings**:
  - [ ] 2FA enabled (required for app passwords)
  - [ ] Generate app-specific password (16 chars)
  - [ ] Use app password, NOT Gmail login password
  - [ ] Allow "Less secure app access" disabled

---

## 🔐 Gmail SMTP Configuration Example

For Gmail SMTP to work with 2FA enabled (recommended):

```
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-16-char-app-password
MAIL_FROM_ADDRESS=your-email@gmail.com
MAIL_FROM_NAME="Vendify"
```

**OR** via admin panel Settings > Email:
- Set the same values in database

---

## 📊 Key Files to Review

1. [config/mail.php](config/mail.php) - Mail driver configuration
2. [app/Providers/AppServiceProvider.php](app/Providers/AppServiceProvider.php#L70-L110) - Applies database settings
3. [app/Models/Setting.php](app/Models/Setting.php) - Settings model
4. [.env.example](.env.example#L67-L82) - Environment variable defaults
5. [config/queue.php](config/queue.php) - Queue configuration
6. [app/Notifications/VendifyVerifyEmailNotification.php](app/Notifications/VendifyVerifyEmailNotification.php) - Email notification class
7. [routes/auth.php](routes/auth.php) - Where emails are triggered

---

## 🚀 NEXT STEPS

1. **Check production `.env`**: Verify `MAIL_MAILER=smtp` is set
2. **Check database settings**: Admin > Settings > Email tab
3. **Check queue worker**: Is it running? Run `php artisan queue:work` if needed
4. **Test**: Send a test email and verify logs
5. **Check logs**: `storage/logs/laravel.log` for SMTP errors
6. **Check Gmail account**: 
   - Ensure 2FA is enabled
   - Generate app-specific password (16 chars)
   - Use the app password, not Gmail login password

---

## 🆘 Common Gmail SMTP Errors

| Error | Solution |
|-------|----------|
| `Authentication failed` | Use app password (16 chars), not Gmail login password |
| `Connection timed out` | Port wrong - use 587 (TLS) not 25 or 465 (unless SSL) |
| `Less secure apps blocked` | Generate app password instead of using login credentials |
| `Email queued but not sent` | Queue worker not running - execute `php artisan queue:work` |
| `Default mail config is log` | Set `MAIL_MAILER=smtp` in production `.env` |

