<?php
/**
 * Uninstall cleanup: settings and the scheduled sync.
 *
 * @package MailKite\Mailboxes
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'mailkite_mailboxes_settings' );
delete_option( 'mailkite_mailboxes_reconcile_cursor' );

wp_clear_scheduled_hook( 'mailkite_mailboxes_reconcile' );

// Per-user mailbox meta is deliberately NOT deleted. `mailkite_smtp_address` and
// `mailkite_smtp_app_password` name a mailbox and a credential that exist on the MailKite
// account, not just in this database. Dropping them here would strand real addresses —
// still receiving mail, still billable, with nothing left in WordPress pointing at them —
// and reinstalling would hand the same user a different address. Someone who wants a
// mailbox gone should release it from the Mailboxes screen while the plugin is installed,
// which revokes the credential upstream too.
//
// Stored mail is the parent plugin's table and is dropped by ITS uninstall, so nothing is
// orphaned by leaving it alone here.
