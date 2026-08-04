<?php
/**
 * Near-live inbox updates, and the scheduled catch-up behind them.
 *
 * @package MailKite\Mailboxes
 */

namespace MailKite\Mailboxes;

defined( 'ABSPATH' ) || exit;

/**
 * Two jobs that answer the same question — "is there new mail?" — at different speeds:
 *
 *  - **Heartbeat** (~15s while the tab is focused) updates the open list IN PLACE.
 *    WordPress already ships this; it backs off when the tab is hidden and costs no new
 *    infrastructure. A full page reload was the alternative, and it discards scroll
 *    position and anything half-typed.
 *  - **Reconcile** (every 15 minutes) copies in whatever the webhook never delivered.
 *    MailKite does not retry failed webhook deliveries, so without this a site that was
 *    briefly down simply loses that mail locally.
 */
final class Live {

	private const RECONCILE_HOOK = 'mailkite_mailboxes_reconcile';
	private const CURSOR_OPTION  = 'mailkite_mailboxes_reconcile_cursor';

	/** How many mailboxes one reconcile pass touches — sync is an API call per mailbox. */
	private const BATCH = 10;

	/**
	 * Hook the live updates and the schedule.
	 */
	public function register(): void {
		add_filter( 'cron_schedules', [ $this, 'add_interval' ] ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- 15 minutes, batched and capped; see BATCH.
		add_action( self::RECONCILE_HOOK, [ self::class, 'reconcile' ] );
		if ( ! wp_next_scheduled( self::RECONCILE_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'mailkite_15min', self::RECONCILE_HOOK );
		}

		add_filter( 'heartbeat_received', [ $this, 'heartbeat' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * A 15-minute interval for the reconcile pass.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Registered schedules.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public function add_interval( array $schedules ): array {
		$schedules['mailkite_15min'] = [
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes (MailKite mailbox sync)', 'mailkite-mailboxes' ),
		];

		return $schedules;
	}

	/**
	 * Catch up a slice of mailboxes.
	 *
	 * Rotates through holders a batch at a time: 500 mailboxes must not become 500 API
	 * calls in one tick. WordPress cron only runs when someone visits the site, so this
	 * is a backstop with a fuzzy schedule — the Sync button is what someone presses when
	 * they are staring at a missing email.
	 */
	public static function reconcile(): void {
		if ( ! Manager::enabled() ) {
			return;
		}
		$holders = array_column( Manager::all_holders(), 'user_id' );
		if ( ! $holders ) {
			return;
		}
		sort( $holders );

		$cursor = (int) get_option( self::CURSOR_OPTION, 0 );
		$slice  = array_slice( $holders, $cursor, self::BATCH );
		if ( ! $slice ) {
			$slice  = array_slice( $holders, 0, self::BATCH );
			$cursor = 0;
		}
		update_option( self::CURSOR_OPTION, $cursor + count( $slice ), false );

		foreach ( $slice as $user_id ) {
			Inbox::sync_user( (int) $user_id ); // Errors are per-user and must not stop the batch.
		}
	}

	/**
	 * Answer the browser's heartbeat with anything newer than what it already shows.
	 *
	 * @param array<string, mixed> $response Outgoing heartbeat payload.
	 * @param array<string, mixed> $data     Incoming payload from the browser.
	 * @return array<string, mixed>
	 */
	public function heartbeat( array $response, array $data ): array {
		if ( empty( $data['mailkite_inbox'] ) || ! is_user_logged_in() ) {
			return $response;
		}
		$since   = (int) $data['mailkite_inbox'];
		$user_id = get_current_user_id();
		$fresh   = [];

		foreach ( \MailKite\Smtp\Log\Store::mailbox_messages( $user_id, 'inbound', 20 ) as $row ) {
			if ( (int) $row->id <= $since ) {
				break; // Newest first, so the first old row ends it.
			}
			$fresh[] = [
				'id'      => (int) $row->id,
				'from'    => (string) $row->from_addr,
				'subject' => '' !== (string) $row->subject ? (string) $row->subject : __( '(no subject)', 'mailkite-mailboxes' ),
				'date'    => (string) get_date_from_gmt( (string) $row->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
				'url'     => esc_url_raw( admin_url( 'admin.php?page=mailkite-inbox&uid=' . (int) $row->id ) ),
			];
		}

		$response['mailkite_inbox'] = $fresh;

		return $response;
	}

	/**
	 * Load the updater on the inbox screen only.
	 *
	 * @param string $hook Current admin page.
	 */
	public function enqueue( string $hook ): void {
		if ( ! str_contains( $hook, 'mailkite-inbox' ) || '' === Manager::address( get_current_user_id() ) ) {
			return;
		}
		wp_enqueue_script( 'heartbeat' );
		wp_add_inline_script( 'heartbeat', $this->script() );
	}

	/**
	 * The client half: tell WordPress what we already have, prepend what comes back.
	 */
	private function script(): string {
		return <<<'JS'
( function ( $ ) {
	var table = document.querySelector( '.mailkite-inbox-list tbody' );
	if ( ! table ) { return; }

	function newestId() {
		var row = table.querySelector( 'tr[data-id]' );
		return row ? parseInt( row.dataset.id, 10 ) : 0;
	}

	$( document ).on( 'heartbeat-send', function ( e, data ) {
		data.mailkite_inbox = newestId();
	} );

	$( document ).on( 'heartbeat-tick', function ( e, data ) {
		var fresh = data.mailkite_inbox;
		if ( ! fresh || ! fresh.length ) { return; }
		// Oldest first so each prepend leaves the newest on top.
		fresh.slice().reverse().forEach( function ( m ) {
			var tr = document.createElement( 'tr' );
			tr.dataset.id = m.id;
			tr.className = 'mailkite-new';
			var from = document.createElement( 'td' );
			from.textContent = m.from;
			var subject = document.createElement( 'td' );
			var a = document.createElement( 'a' );
			a.href = m.url;
			var strong = document.createElement( 'strong' );
			strong.textContent = m.subject;
			a.appendChild( strong );
			subject.appendChild( a );
			var date = document.createElement( 'td' );
			date.textContent = m.date;
			tr.appendChild( from );
			tr.appendChild( subject );
			tr.appendChild( date );
			table.insertBefore( tr, table.firstChild );
		} );
		var empty = table.querySelector( 'tr.mailkite-empty' );
		if ( empty ) { empty.remove(); }
	} );
}( jQuery ) );
JS;
	}
}
