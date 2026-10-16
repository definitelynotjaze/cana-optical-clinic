================================================================
 CANA OPTICAL CLINIC — HOSTINGER DEPLOYMENT GUIDE
================================================================

This zip is a fresh-install package: empty database, no patient data,
no uploaded photos. Follow these steps in order.

----------------------------------------------------------------
1. CREATE THE DATABASE
----------------------------------------------------------------
In hPanel: Databases -> MySQL Databases -> Create a new database.
Hostinger will generate a database name and username for you (usually
prefixed with your account ID) — set a strong password. Write down:
  - Database host   (almost always "localhost")
  - Database name
  - Database user
  - Database password

----------------------------------------------------------------
2. UPLOAD THE FILES
----------------------------------------------------------------
Upload the ENTIRE contents of this zip into your domain's public_html
folder (or a subfolder, if this isn't the only site on the account) —
via hPanel File Manager's "Extract" on an uploaded zip, or via FTP.
FTP is strongly recommended: this includes ~150MB of video assets and
File Manager's browser upload can time out on files that large.

----------------------------------------------------------------
3. IMPORT THE DATABASE SCHEMA
----------------------------------------------------------------
In hPanel: Databases -> phpMyAdmin -> select your new database ->
Import tab -> choose database/schema.sql from this package -> Go.
This creates all tables, empty (no demo/sample data).

----------------------------------------------------------------
4. CONFIGURE .env
----------------------------------------------------------------
Edit the `.env` file at the root of your upload (same folder as
app.html) and fill in:
  - MYSQLHOST / MYSQLDATABASE / MYSQLUSER / MYSQLPASSWORD from step 1
  - BREVO_API_KEY — recommended over SMTP_* on shared hosting: sign in
    at https://app.brevo.com -> SMTP & API -> API Keys tab -> Generate
    a new API key, paste it in. This sends over HTTPS instead of SMTP
    port 587, which some shared hosts block. Also set SMTP_FROM to an
    email/domain you've verified as a Sender in Brevo (Senders, Domains
    & Dedicated IPs -> Senders) — an unverified sender gets rejected
    even with a valid API key.
  - If you'd rather use SMTP instead, leave BREVO_API_KEY blank and
    fill in SMTP_HOST/PORT/USERNAME/PASSWORD/FROM instead.
  - IMPORTANT: leave any of the above completely blank (not filled with
    placeholder text) if you're not using it — a blank value correctly
    falls back to this app's built-in Gmail default; a value left as
    literal placeholder text (e.g. "your-smtp-login@email.com") does
    not, and email sending will fail with an authentication error.
  - CRON_SECRET — any long random string you make up.
  - APP_URL — the live site's address with no trailing slash, e.g.
    https://canaoptical.com. Used for links in emails (the reminder's
    Confirm Appointment button). Leave it OUT of a local XAMPP .env —
    locally the links work it out on their own (http://localhost/...).

`.env` is already blocked from direct web access by .htaccess, but
never share its contents or commit it anywhere.

Once deployed, you can sanity-check email delivery by visiting
/api/test-smtp.php on your live domain — it reports which mode is
active (brevo-api vs smtp-fallback) and the exact error if sending
fails. DELETE that file once email is confirmed working — it's a
public, unauthenticated debug endpoint and shouldn't stay live.

----------------------------------------------------------------
5. CREATE YOUR FIRST ADMIN ACCOUNT
----------------------------------------------------------------
The database starts completely empty — including no admin account —
and the app itself has no public way to create one (by design: public
registration only creates patient accounts). Use the one-time bootstrap
script instead.

If your Hostinger plan has SSH access:
  ssh into your account, then:
  cd public_html/database
  php create_first_admin.php you@yourdomain.com "a-strong-password" "Your Name"

If you don't have SSH access:
  a) Temporarily rename database/.htaccess to database/.htaccess.bak
     (via File Manager) so the script is reachable over the web.
  b) Visit, once:
     https://yourdomain.com/database/create_first_admin.php?email=you@yourdomain.com&password=a-strong-password&name=Your+Name
  c) Confirm it says "Admin account created."
  d) Rename database/.htaccess.bak back to database/.htaccess immediately.

Either way: DELETE database/create_first_admin.php once you've
confirmed you can log in. It's a standing way to create an admin
account for anyone who can reach it, so it should not stay on the
server after first use.

----------------------------------------------------------------
6. SET FOLDER PERMISSIONS
----------------------------------------------------------------
The app writes to these folders at runtime — make sure they're
writable by the web server (755 is usually sufficient on Hostinger;
File Manager -> right-click -> Permissions):
  - assets/uploads/  (and its subfolders)
  - storage/backups/

----------------------------------------------------------------
7. SET UP THE APPOINTMENT REMINDER CRON
----------------------------------------------------------------
The app needs something to hit this URL every 5 minutes:
  https://yourdomain.com/api/cron/appointment_reminders.php?key=YOUR_CRON_SECRET
(YOUR_CRON_SECRET = whatever you set as CRON_SECRET in .env)

Either:
  - hPanel -> Advanced -> Cron Jobs -> add a job that curls that URL
    every 5 minutes, or
  - use a free external pinger like https://cron-job.org pointed at
    the same URL (this is what the existing Railway deployment uses,
    via .github/workflows/appointment-cron.yml, if you have that too).

----------------------------------------------------------------
8. LOG IN AND VERIFY
----------------------------------------------------------------
Visit https://yourdomain.com/app.html and log in with the admin
account from step 5. From there, use User Management to create
staff/doctor accounts, and Clinic Settings to configure the clinic's
services, hours, and branding.

The public marketing site is index.html (same folder) — set that as
your domain's actual homepage/index if you want visitors to land there
instead of the app directly.
================================================================
