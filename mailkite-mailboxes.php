<?php
/**
 * Plugin Name:       MailKite Mailboxes – Real Email Addresses & Inbox for WordPress Users
 * Plugin URI:        https://mailkite.dev/docs/integrations/wordpress
 * Description:       Give WordPress users a real email address on your domain — readable in wp-admin, on the front end, and in any IMAP mail client.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  mailkite-smtp
 * Author:            MailKite
 * Author URI:        https://mailkite.dev
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mailkite-mailboxes
 *
 * @package MailKite\Mailboxes
 */

namespace MailKite\Mailboxes;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.1.0';

define( 'MAILKITE_MAILBOXES_DIR', plugin_dir_path( __FILE__ ) );

spl_autoload_register(
	static function ( string $class_name ): void {
		if ( ! str_starts_with( $class_name, __NAMESPACE__ . '\\' ) ) {
			return;
		}
		$path = MAILKITE_MAILBOXES_DIR . 'src/' . str_replace( '\\', '/', substr( $class_name, strlen( __NAMESPACE__ ) + 1 ) ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		// WP 6.5's `Requires Plugins` header keeps MailKite SMTP installed and active, but a
		// half-finished update or a must-use load-order quirk can still get us here alone.
		// Failing loudly beats fataling on a missing parent class.
		if ( ! class_exists( '\\MailKite\\Smtp\\Options' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>'
						. esc_html__( 'MailKite Mailboxes needs the MailKite SMTP plugin to be active — it provides the account connection and domain.', 'mailkite-mailboxes' )
						. '</p></div>';
				}
			);

			return;
		}

		( new Inbox() )->register();
		( new Live() )->register();
		( new Admin() )->register();
	},
	20 // After MailKite SMTP has booted.
);
