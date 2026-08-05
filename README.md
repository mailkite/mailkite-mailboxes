# MailKite Mailboxes

Give the people who use your site a real email address on your domain — readable in
wp-admin, on the front end via `[mailkite_inbox]`, and in any IMAP client.

Add-on for [MailKite SMTP](https://github.com/mailkite/mailkite-smtp) (enforced through
WordPress 6.5's `Requires Plugins` header): that plugin owns the MailKite connection,
this one owns who gets an address and how they read it.

## Install

Install [MailKite SMTP](https://github.com/mailkite/mailkite-smtp/releases/latest) first —
this plugin will not activate without it. Then download the zip from the
[latest release](https://github.com/mailkite/mailkite-mailboxes/releases/latest) and use
**Plugins → Add New → Upload Plugin**.

Requires WordPress 6.5+ and PHP 8.1+. Everything is off until an administrator turns it on.

## How it works

Each mailbox is provisioned as a MailKite **app password scoped to that one address**, with
both `imap` and `api` protocols — the same secret is the user's IMAP password and the bearer
their in-WordPress inbox reads with. The site's account key only mints and revokes; it never
reads anyone's mail.

Mail itself is **not** fetched per page view. The parent plugin's webhook writes every
message into WordPress once, and this plugin reads it back through `Log\Store` — the one
shared API, so an add-on never writes another plugin's schema. Rows carry `owner_user_id`,
and ownership is enforced in the query, which is what keeps personal mail out of the
site-wide Email Log.

Two things keep the inbox current, and neither is a button:

- **Heartbeat** (~15s while the tab is focused) adds new rows to the open list in place, so
  a half-written reply is never thrown away by a reload.
- **A 15-minute WP-Cron reconcile** copies in anything a webhook attempt missed, rotating
  through mailboxes in batches. WP-Cron only fires on page visits, so a quiet site should
  set `DISABLE_WP_CRON` and call `wp-cron.php` from a real cron.

## Development

```sh
composer install
composer run lint     # PHPCS (WordPress Coding Standards)
composer run stan     # PHPStan level 6
bin/build-zip.sh      # -> dist/mailkite-mailboxes.zip
```

Run Plugin Check against `dist/`, not the repo. Note wp.org forbids the word "WordPress" in
a plugin *name* — that is why the title says "for Your Users".

GPLv2. See [readme.txt](readme.txt) for the user-facing description.
