<?php
/**
 * This add-on's own settings (the MailKite connection stays in MailKite SMTP).
 *
 * @package MailKite\Mailboxes
 */

namespace MailKite\Mailboxes;

defined( 'ABSPATH' ) || exit;

/**
 * Mailbox policy for the site. Deliberately a SEPARATE option row from MailKite
 * SMTP's: the parent owns the connection (API key, domains, mailers), this owns
 * who may hold an address and under what rules. Uninstalling one never disturbs
 * the other's configuration.
 */
final class Settings {

	public const OPTION = 'mailkite_mailboxes_settings';

	/** Role addresses (RFC 2142 + the usual impersonation targets) nobody may claim. */
	public const DEFAULT_RESERVED = 'postmaster, abuse, admin, administrator, root, security, hostmaster, webmaster, billing, support, sales, info, contact, help, legal, privacy, dmca, noreply, no-reply, mail, smtp, imap, api, www, test';

	private const DEFAULTS = [
		'enabled'       => false,
		'domain'        => '',
		'auto_assign'   => false,
		'self_register' => false,
		'roles'         => [ 'administrator' ],
		'reserved'      => self::DEFAULT_RESERVED,
		'send_limit'    => 200,
	];

	/**
	 * All settings merged over defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, [] );

		return array_merge( self::DEFAULTS, is_array( $stored ) ? $stored : [] );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( string $key ) {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Sanitize and persist a partial update.
	 *
	 * @param array<string, mixed> $input Raw input.
	 */
	public static function update( array $input ): void {
		$clean = [];

		foreach ( [ 'enabled', 'auto_assign', 'self_register' ] as $flag ) {
			if ( isset( $input[ $flag ] ) ) {
				$clean[ $flag ] = filter_var( $input[ $flag ], FILTER_VALIDATE_BOOLEAN );
			}
		}
		if ( isset( $input['domain'] ) ) {
			$clean['domain'] = strtolower( sanitize_text_field( (string) $input['domain'] ) );
		}
		if ( isset( $input['reserved'] ) ) {
			$clean['reserved'] = sanitize_textarea_field( (string) $input['reserved'] );
		}
		if ( isset( $input['send_limit'] ) ) {
			$clean['send_limit'] = max( 0, (int) $input['send_limit'] );
		}
		if ( isset( $input['roles'] ) && is_array( $input['roles'] ) ) {
			$clean['roles'] = array_values( array_filter( array_map( 'sanitize_key', $input['roles'] ) ) );
		}

		update_option( self::OPTION, array_merge( self::all(), $clean ), false );
	}
}
