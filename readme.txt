=== MailKite Mailboxes – Real Email Addresses & Inbox for WordPress Users ===
Contributors: bucabay
Tags: email, inbox, imap, mailbox, webmail
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.1
Requires Plugins: mailkite-smtp
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Give WordPress users a real email address on your domain — read it in wp-admin, on your site, or in any IMAP mail client.

== Description ==

An add-on for **MailKite SMTP**. Where that plugin makes your site's email work, this one gives the *people* on your site their own address on your domain: `jane@yourdomain.com`, readable anywhere.

**Free forever. No Pro tier. Open source.**

= What it does =

* **Real addresses for WordPress users** — assign `{username}@yourdomain.com` automatically, or let users choose their own. Both are off until you switch them on, and an address can be changed or moved to a new domain later.
* **A real inbox in WordPress** — an Inbox screen in wp-admin plus a `[mailkite_inbox]` shortcode for members-only pages. Read, reply, and compose new messages.
* **Works in any mail client** — each mailbox comes with IMAP settings and an app password, so Apple Mail, Thunderbird or a phone works out of the box.
* **The same password is an API token** for that one address, so an agent or a script can read the mailbox over HTTPS.
* **You stay in control** — restrict by role, reserve addresses nobody may claim (`postmaster`, `billing`, …), cap how much each user can send per day, and revoke any mailbox at a click.

= Security =

Each mailbox gets a credential scoped to that single address — never an account-wide key — encrypted at rest. A user can only read their own mail, and can only send *as* their own address. Deleting a WordPress user revokes their credential.

= Requirements =

MailKite SMTP (this add-on uses its account connection) and a domain receiving mail on MailKite.

== Frequently Asked Questions ==

= Do users need a MailKite account? =

No. The site connects once through MailKite SMTP; users just get an address.

= Can users send from someone else's address? =

No. The From address is set by the server to the address they hold.

== Changelog ==

= 0.1.0 =
* Split out of MailKite SMTP 0.3.0 as its own plugin: mailbox policy, address claiming, credentials screen, Inbox screen, `[mailkite_inbox]`, reply.
* Compose: write a new message from your mailbox, with recipient validation and the same daily send limit.
* Inbox and Sent folders, a Refresh button, and the list refreshes itself every minute so new mail turns up on its own.
* Change an address, or move a mailbox to the site's current domain, without losing the old one to a failed attempt.
* All mailbox actions live on their own "Your address" screen; the profile page shows a read-only summary and links to it.
