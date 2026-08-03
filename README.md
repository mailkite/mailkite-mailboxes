# MailKite Mailboxes

Give WordPress users a real email address on your domain — readable in wp-admin, on the
front end via `[mailkite_inbox]`, and in any IMAP client.

Add-on for [MailKite SMTP](https://github.com/mailkite/mailkite-smtp) (enforced through
WordPress 6.5's `Requires Plugins` header): that plugin owns the MailKite connection,
this one owns who gets an address and how they read it.

Each mailbox is provisioned as a MailKite **app password scoped to that one address**,
with both `imap` and `api` protocols — the same secret is the user's IMAP password and
the bearer their in-WordPress inbox reads with. The site's account key only mints and
revokes; it never reads anyone's mail.

GPLv2. See the plugin's readme.txt for the user-facing description.
