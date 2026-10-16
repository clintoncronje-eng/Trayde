# Trayde website — 1-grid hosting setup

Design, colours and fonts are unchanged. What changed is the plumbing so the site runs on 1-grid's cPanel (PHP) hosting instead of Netlify.

## What's in the folder
```
public_html/
  index.html        ← the website (same look)
  enquiry.php       ← contact form → saved on server + emailed to you
  publish.php       ← Dashboard "Publish Changes" → makes edits live for everyone
  .htaccess         ← HTTPS, security, caching
  .user.ini         ← bigger upload limits for photos
  inc/config.php    ← YOUR SETTINGS (password, email)  — not readable from web
  inc/lib.php
  data/             ← private: enquiries, supplier details, backups — not readable from web
  uploads/          ← photos you upload in the dashboard (scripts blocked)
```
`site-data.json` is created by the server the first time you publish.

## Install (about 10 minutes)
1. **cPanel → Domains**: make sure trayde.co.za points to this hosting package.
2. **cPanel → SSL/TLS Status → Run AutoSSL** (free HTTPS). Wait until the padlock shows.
3. **cPanel → Email Accounts**: create `website@trayde.co.za` (used as the sender for enquiry emails).
4. **cPanel → File Manager → public_html**: upload the *contents* of `public_html/` (zip it, upload, Extract). Tick "Show hidden files" so `.htaccess` and `.user.ini` come along.
5. Edit **inc/config.php**:
   - `PUBLISH_PASSWORD` → a long secret only you know
   - `NOTIFY_EMAIL` → where enquiries go
   - `MAIL_FROM` → the mailbox from step 3
6. **cPanel → MultiPHP Manager**: set PHP **8.1 or newer**.
7. Permissions (usually automatic): folders `755`, files `644`. `data/` and `uploads/` must be writable by PHP.

## Test
- Visit `https://trayde.co.za/data/enquiries.json` → must show **403 Forbidden** (privacy check).
- Submit the contact form → you get an email, and a success message on screen.
- Owner login → Dashboard → change some text → **Publish** tab → enter the publish password → **Publish Changes**. Open the site in another browser/phone: change is live.
- Dashboard → **Enquiries → Sync from website** → submitted enquiries appear.

## How it works now
- **Dashboard edits** save on your device as you type, then go live when you press *Publish Changes*.
- **Photos** uploaded in the dashboard are converted to real image files in `/uploads/` on publish (faster site).
- **Supplier details** ("Purchased from", purchase price) and **enquiries** are stored only in the private `data/` folder — they are stripped from anything public.
- The last 10 published versions are backed up in `data/backup-*.json`.

## Notes
- The dashboard login password (in the browser) and the publish password (in config.php) are separate. The publish password is the one that actually protects the live site.
- If enquiry emails land in spam, in cPanel → Email Deliverability click **Repair** for SPF/DKIM.
- Images still hotlinked from i.ibb.co / qwenlm work fine; for long-term reliability, re-upload them via Dashboard → Media and publish, which moves them onto your own hosting.

