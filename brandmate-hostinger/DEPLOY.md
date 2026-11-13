# Putting BrandMate on Hostinger shared hosting

One domain serves everything: the app at `https://yourdomain.com` and the API
at `https://yourdomain.com/api/v1`.

On the server it looks like this:

```
domains/yourdomain.com/
  public_html/     the website (Angular files, index.php, .htaccess)
  brandmate/       the Laravel code and .env, not reachable from the internet
```

Replace `yourdomain.com` and `u000000000` with your own values throughout.

## Before you start (in hPanel)

1. **PHP version:** set it to 8.3 or newer (Advanced > PHP Configuration).
2. **SSL:** make sure the free SSL certificate is installed for the domain.
3. **Database:** Databases > MySQL Databases > create a database and user.
   Write down the database name, user name and password.
4. **Mailbox:** Emails > create `no-reply@yourdomain.com` and note its password.
5. **SSH:** Advanced > SSH Access > enable it. Note the IP, port and user name.
   If your plan has no SSH, stop here and ask for the no-SSH route.

## 1. Build the upload files (on your PC)

```powershell
cd C:\Users\monis\Projects\influencer
.\deploy\hostinger\build-release.ps1
```

If PowerShell refuses to run the script, run this once and try again:
`Set-ExecutionPolicy -Scope CurrentUser RemoteSigned`

You get two files in the `release` folder: `public_html.zip` and `brandmate.zip`.

## 2. Upload (hPanel > File Manager)

1. Open `domains/yourdomain.com/`.
2. Upload `brandmate.zip` there and extract it. You now have a `brandmate`
   folder next to `public_html`.
3. Open `public_html`, delete what Hostinger put there (for example
   `default.php`), upload `public_html.zip` and extract it **directly into**
   `public_html`. `index.html`, `index.php` and `.htaccess` must sit in
   `public_html` itself, not in a subfolder. Turn on "show hidden files" to
   see `.htaccess`.
4. Delete both zip files from the server.

## 3. Create the settings file

1. In File Manager, create `domains/yourdomain.com/brandmate/.env`.
2. Paste the contents of `deploy\hostinger\env.production.example`.
3. Fill in every line marked CHANGE: the domain, the database details, the
   mailbox password, and your own platform-owner e-mail and password.

## 4. Install and set up (SSH)

Connect from PowerShell (hPanel shows the exact command):

```powershell
ssh -p 65002 u000000000@YOUR.SERVER.IP
```

Then:

```bash
cd ~/domains/yourdomain.com/brandmate
php -v                      # must say 8.3 or newer
composer2 install --no-dev --optimize-autoloader
php artisan key:generate --force
php artisan migrate --force
php artisan db:seed --force
php artisan config:cache
php artisan route:cache
```

- If `composer2` is not found, use `composer`.
- If `php -v` shows an older version than you chose in hPanel, the command
  line uses a different PHP. Ask Hostinger support for the path of the PHP 8.3
  command-line binary and use that path in place of `php` here and in step 5.

## 5. Cron job (processes queued e-mails)

hPanel > Advanced > Cron Jobs > add a custom job that runs **every minute**:

```
cd /home/u000000000/domains/yourdomain.com/brandmate && php artisan queue:work database --stop-when-empty --tries=3 --backoff=10 >> storage/logs/queue-cron.log 2>&1
```

Set `MAIL_MAILER=smtp`, `MAIL_SCHEME=smtps`, `MAIL_HOST=smtp.hostinger.com`,
`MAIL_PORT=465`, the mailbox's full address as `MAIL_USERNAME`, its password as
`MAIL_PASSWORD`, and `QUEUE_CONNECTION=database` in the production `.env` before
caching the configuration. If Hostinger's Email > Connect apps and devices page
shows different SMTP settings for this mailbox, use those values instead.
The authentication and invitation notifications are queued. This worker
processes pending jobs and exits, making it suitable for Hostinger's
once-a-minute cron without leaving a worker running. Emails should go out
within about a minute. Check `storage/logs/queue-cron.log` and
`storage/logs/laravel.log` if a job fails. These log files are created only
when a command or Laravel writes to them; their absence before the first run
is normal. To see worker errors directly over SSH, run `php artisan queue:work
database --stop-when-empty --tries=3 --backoff=10 -vvv` from the application
directory.

## 6. Check it

1. `https://yourdomain.com/up` shows a small "Application up" page: the API runs.
2. `https://yourdomain.com` shows the BrandMate sign-in page.
3. Register an account with a real e-mail address. The confirmation e-mail
   should arrive within a minute or two (check spam).
4. Sign in with the platform-owner e-mail from `.env` and open Platform admin.

If a page shows an error, look at the newest file in
`brandmate/storage/logs/`. Do not set `APP_DEBUG=true` on the live site: it
shows passwords and keys to visitors.

## Releasing an update later

1. On your PC: run `build-release.ps1` again.
2. In File Manager: extract `public_html.zip` over `public_html`, and
   `brandmate.zip` over `brandmate`. Your `.env`, `vendor` and `storage`
   stay, because the zip does not contain them.
3. Over SSH:

```bash
cd ~/domains/yourdomain.com/brandmate
composer2 install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:seed --force
php artisan config:cache
php artisan route:cache
php artisan queue:restart
```

## What shared hosting cannot do

- E-mails are delayed by up to a minute (cron instead of a live worker).
- No Redis, so caching and rate limiting use the database. Fine for early
  traffic; move to a VPS when the site gets busy.
- Back up the database yourself (hPanel > Files > Backups) before each update.
