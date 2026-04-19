# Dawa Alert System

Dawa Alert System is a PHP and MySQL medication reminder dashboard for doctors. It supports:

- doctor login and registration
- patient management
- medication scheduling
- live timeline and reminder tracking
- missed medication alerts
- password reset by email
- SMS reminder integration through Africa's Talking

## Stack

- PHP
- MySQL
- XAMPP or any Apache/PHP/MySQL environment
- PHPMailer

## Project Structure

- `dashboard.php` - main doctor dashboard
- `login.php` - doctor login
- `register.php` - doctor registration
- `forgotpassword.php` - forgot password form
- `sendrestpassword.php` - sends password reset email
- `reset-password.php` - reset password page
- `proces-reset-password.php` - saves new password
- `reminder.php` - sends due reminders and processes inbound SMS replies
- `db.php` - database connection
- `mailer.php` - SMTP mail setup
- `seed_medicines.php` - seeds medicine catalog
- `app_config.example.php` - example configuration template

## Requirements

- PHP 8.x recommended
- MySQL or MariaDB
- Apache
- Composer dependencies installed

## Local Deployment With XAMPP

1. Copy the project into your XAMPP web root:

```text
C:\xampp\htdocs\dawa_alert
```

2. Start:

- Apache
- MySQL

3. Install dependencies if needed:

```powershell
composer install
```

If Composer is not globally installed but `composer.phar` exists in the project:

```powershell
php composer.phar install
```

## Database Setup

1. Create a database named:

```text
dawa_alert
```

2. Update [db.php](./db.php) if your MySQL credentials are different.

Default local values in this project are:

- host: `localhost`
- user: `root`
- password: empty
- database: `dawa_alert`

3. Create the required tables:

- `doctors`
- `patients`
- `medications`
- `reminder_logs`
- `medicines`

If you already used the app locally, some tables may already exist.

## App Configuration

Sensitive credentials are not committed. Create a local config file:

1. Copy:

```text
app_config.example.php
```

to:

```text
app_config.php
```

2. Fill in your real values for:

- app base URL
- SMTP email credentials
- Africa's Talking credentials

## Mail Setup

Password reset emails use PHPMailer through SMTP.

Set these in `app_config.php`:

- `host`
- `port`
- `encryption`
- `username`
- `password`
- `from_email`
- `from_name`

For Gmail SMTP, common values are:

- host: `smtp.gmail.com`
- port: `587`
- encryption: `tls`

## Africa's Talking SMS Setup

Reminder SMS uses Africa's Talking.

Set these in `app_config.php`:

- `username`
- `api_key`
- `sms_endpoint`

Sandbox example:

- username: `sandbox`
- endpoint: `https://api.sandbox.africastalking.com/version1/messaging`

## Inbound SMS Reply Format

The app currently expects simple replies:

- `1` for taken
- `2` for missed

Replies are matched to the most recently reminded pending medication for the sender phone number.

Your Africa's Talking callback should point to:

```text
http://your-domain/dawa_alert/reminder.php
```

or for local tunnel testing:

```text
https://your-public-tunnel-url/reminder.php
```

## Seed The Medicine Catalog

To load the medicine catalog into the database:

```powershell
php seed_medicines.php
```

## Open The App

Local browser:

```text
http://localhost/dawa_alert/
```

Phone on same Wi-Fi:

```text
http://YOUR-PC-IP/dawa_alert/
```

## GitHub Deployment Notes

- `app_config.php` is ignored by Git and must be created manually on each machine
- `vendor/` is ignored, so run Composer after cloning
- rotate any credentials that were previously placed directly in source files

## Common Commands

Syntax check:

```powershell
php -l dashboard.php
php -l reminder.php
php -l sendrestpassword.php
```

Send reminders manually:

```powershell
php reminder.php
```

## Current Limitations

- no migration system yet
- no admin UI for managing the medicine catalog
- simple SMS reply matching can be ambiguous if one patient has multiple pending reminders close together

## Repository

GitHub:

```text
https://github.com/Gromyko91/DAWAALERT-SYSTEM
```
