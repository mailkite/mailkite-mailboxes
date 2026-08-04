<?php
/**
 * The in-WordPress inbox: message list, reader, and reply.
 *
 * @package MailKite\Mailboxes
 */

namespace MailKite\Mailboxes;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a user's own mailbox — in wp-admin (menu: Inbox) and on the front end
 * via `[mailkite_inbox]`. Every read uses THAT user's app password, so the screen
 * can only ever show mail the credential itself is scoped to.
 */
final class Inbox {

	/**
	 * Hook the admin page, shortcode, and reply handler.
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_shortcode( 'mailkite_inbox', [ $this, 'shortcode' ] );
		add_action( 'admin_post_mailkite_mailboxes_reply', [ $this, 'handle_reply' ] );
		add_action( 'admin_post_mailkite_mailboxes_compose', [ $this, 'handle_compose' ] );
		add_filter( 'mailkite_smtp_mailbox_owner', [ Manager::class, 'owner_of_address' ], 10, 2 );
	}

	/**
	 * "Inbox" menu for any logged-in user who holds an address.
	 */
	public function add_menu(): void {
		if ( '' === Manager::address( get_current_user_id() ) ) {
			return;
		}
		add_menu_page(
			__( 'Inbox', 'mailkite-mailboxes' ),
			__( 'Inbox', 'mailkite-mailboxes' ),
			'read',
			'mailkite-inbox',
			[ $this, 'render_admin_page' ],
			'dashicons-email',
			26
		);
	}

	/**
	 * wp-admin → Inbox.
	 */
	public function render_admin_page(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Inbox', 'mailkite-mailboxes' ) . '</h1>';
		// render() assembles its own markup with every dynamic value escaped at the point
		// of use (esc_html/esc_attr/esc_url, and wp_kses_post for the message body). It is
		// NOT passed through wp_kses_post here: that filter allows post content only, so it
		// would strip the reply form's inputs — including the nonce.
		echo $this->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped at assembly, see above.
		echo '</div>';
	}

	/**
	 * `[mailkite_inbox]` — the same screen on the front end.
	 *
	 * @return string
	 */
	public function shortcode(): string {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Please sign in to read your mail.', 'mailkite-mailboxes' ) . '</p>';
		}

		return '<div class="mailkite-inbox">' . $this->render() . '</div>';
	}

	/**
	 * List or reader, depending on ?uid.
	 *
	 * @return string HTML (already escaped).
	 */
	private function render(): string {
		$user_id = get_current_user_id();
		$address = Manager::address( $user_id );
		if ( '' === $address ) {
			return '<p>' . esc_html__( 'You do not have a mailbox yet.', 'mailkite-mailboxes' ) . '</p>';
		}
		$secret = Manager::secret( $user_id );
		if ( '' === $secret ) {
			return '<p>' . esc_html__( 'Your mailbox credential is missing — regenerate it from your profile.', 'mailkite-mailboxes' ) . '</p>';
		}

		$notice = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only message passed back by our own redirect.
		if ( isset( $_GET['mailkite_error'] ) ) {
			$notice = '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['mailkite_error'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( isset( $_GET['mailkite_sent'] ) ) {
			$notice = '<div class="notice notice-success"><p>' . esc_html__( 'Message sent.', 'mailkite-mailboxes' ) . '</p></div>';
		}

		$uid = isset( $_GET['uid'] ) ? absint( $_GET['uid'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch.
		if ( isset( $_GET['compose'] ) ) {
			return $notice . $this->render_compose( $address );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch.
		$folder = ( isset( $_GET['folder'] ) && 'sent' === $_GET['folder'] ) ? 'Sent' : 'INBOX';

		return $notice . ( $uid
			? $this->render_message( $secret, $address, $uid, $folder )
			: $this->render_list( $secret, $address, $folder ) );
	}

	/**
	 * The message list.
	 *
	 * @param string $secret  App password.
	 * @param string $address Mailbox address.
	 * @return string
	 */
	private function render_list( string $secret, string $address, string $mailbox = 'INBOX' ): string {
		// Read the LOCAL archive the webhook fills, not the API: MailKite's retention is
		// finite, and a round-trip per page view is what made this screen slow.
		$messages = \MailKite\Smtp\Log\Store::mailbox_messages(
			get_current_user_id(),
			'Sent' === $mailbox ? 'outbound' : 'inbound',
			100
		);

		$is_sent   = 'Sent' === $mailbox;
		$inbox_url = esc_url( remove_query_arg( [ 'folder', 'uid', 'compose' ] ) );
		$sent_url  = esc_url( add_query_arg( 'folder', 'sent', remove_query_arg( [ 'uid', 'compose' ] ) ) );

		$html  = '<p style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">'
			. '<a class="button button-primary" href="' . esc_url( add_query_arg( 'compose', 1 ) ) . '">'
			. esc_html__( 'Compose', 'mailkite-mailboxes' ) . '</a>'
			. '<span><a href="' . $inbox_url . '" style="margin-right:10px' . ( $is_sent ? '' : ';font-weight:600' ) . '">'
			. esc_html__( 'Inbox', 'mailkite-mailboxes' ) . '</a>'
			. '<a href="' . $sent_url . '"' . ( $is_sent ? ' style="font-weight:600"' : '' ) . '>'
			. esc_html__( 'Sent', 'mailkite-mailboxes' ) . '</a></span>'
			. '<span>' . sprintf(
				/* translators: %s: the user's email address. */
				esc_html__( 'Mail for %s', 'mailkite-mailboxes' ),
				'<code>' . esc_html( $address ) . '</code>'
			) . '</span></p>'
			// In wp-admin the Heartbeat API streams new rows into this table in place (see
			// Live). On the front end wp.heartbeat is not enqueued, so that view falls back
			// to a slow reload — still only ever on the LIST, never over a reply someone is
			// part-way through writing.
			. ( is_admin() ? '' : '<script>window.setTimeout(function(){window.location.reload();}, 120000);</script>' );
		$html .= '<table class="widefat striped mailkite-inbox-list"><thead><tr>'
			. '<th>' . ( $is_sent ? esc_html__( 'To', 'mailkite-mailboxes' ) : esc_html__( 'From', 'mailkite-mailboxes' ) ) . '</th>'
			. '<th>' . esc_html__( 'Subject', 'mailkite-mailboxes' ) . '</th>'
			. '<th>' . ( $is_sent ? esc_html__( 'Sent', 'mailkite-mailboxes' ) : esc_html__( 'Received', 'mailkite-mailboxes' ) ) . '</th>'
			. '</tr></thead><tbody>';

		if ( ! $messages ) {
			$html .= '<tr class="mailkite-empty"><td colspan="3">'
				. ( $is_sent ? esc_html__( 'Nothing sent from this address yet.', 'mailkite-mailboxes' ) : esc_html__( 'No mail yet.', 'mailkite-mailboxes' ) )
				. '</td></tr>';
		}
		foreach ( $messages as $m ) {
			$unread  = ! $is_sent && empty( $m->seen );
			$subject = (string) $m->subject;
			$html   .= '<tr data-id="' . esc_attr( (string) $m->id ) . '">'
				. '<td>' . esc_html( (string) ( $is_sent ? $m->mail_to : $m->from_addr ) ) . '</td>'
				. '<td><a href="' . esc_url( add_query_arg( 'uid', (int) $m->id ) ) . '">'
					. ( $unread ? '<strong>' : '' )
					. esc_html( '' !== $subject ? $subject : __( '(no subject)', 'mailkite-mailboxes' ) )
					. ( $unread ? '</strong>' : '' )
				. '</a></td>'
				. '<td>' . esc_html( $this->local_time( (string) $m->created_at ) ) . '</td>'
				. '</tr>';
		}

		return $html . '</tbody></table>';
	}

	/**
	 * One message, and the reply box.
	 *
	 * @param string $secret  App password.
	 * @param string $address Mailbox address.
	 * @param int    $uid     Message uid.
	 * @return string
	 */
	private function render_message( string $secret, string $address, int $uid, string $mailbox = 'INBOX' ): string {
		// Store scopes the lookup to this user, so someone else's id simply finds nothing.
		$row = \MailKite\Smtp\Log\Store::get( $uid, get_current_user_id() );
		if ( ! $row ) {
			return '<p>' . esc_html__( 'That message is not in your mailbox.', 'mailkite-mailboxes' ) . '</p>';
		}
		\MailKite\Smtp\Log\Store::mark_seen( $uid, get_current_user_id() );

		$is_sent = 'outbound' === $row->direction;
		$back    = remove_query_arg( 'uid' );
		$subject = '' !== (string) $row->subject ? (string) $row->subject : __( '(no subject)', 'mailkite-mailboxes' );

		$html  = '<p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Back to the inbox', 'mailkite-mailboxes' ) . '</a></p>';
		$html .= '<table class="widefat striped" style="max-width:860px"><tbody>'
			. '<tr><td style="width:7em"><strong>' . esc_html__( 'From', 'mailkite-mailboxes' ) . '</strong></td><td>' . esc_html( (string) $row->from_addr ) . '</td></tr>'
			. '<tr><td><strong>' . esc_html__( 'To', 'mailkite-mailboxes' ) . '</strong></td><td>' . esc_html( (string) $row->mail_to ) . '</td></tr>'
			. '<tr><td><strong>' . esc_html__( 'Subject', 'mailkite-mailboxes' ) . '</strong></td><td>' . esc_html( $subject ) . '</td></tr>'
			. '<tr><td><strong>' . esc_html__( 'Date', 'mailkite-mailboxes' ) . '</strong></td><td>' . esc_html( $this->local_time( (string) $row->created_at ) ) . '</td></tr>'
			. '</tbody></table>';

		if ( ! empty( $row->body_text ) ) {
			$html .= '<pre style="background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:16px;max-width:860px;white-space:pre-wrap">'
				. esc_html( (string) $row->body_text ) . '</pre>';
		} elseif ( ! empty( $row->body_html ) ) {
			$html .= '<div style="background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:16px;max-width:860px">'
				. wp_kses_post( (string) $row->body_html ) . '</div>';
		} else {
			$html .= '<p>' . esc_html__( '(no readable body)', 'mailkite-mailboxes' ) . '</p>';
		}

		$files = \MailKite\Smtp\Log\Store::attachments( $uid );
		if ( $files ) {
			$html .= '<h2>' . esc_html__( 'Attachments', 'mailkite-mailboxes' ) . '</h2><ul>';
			foreach ( $files as $file ) {
				$html .= '<li>' . ( $file->url
					? '<a href="' . esc_url( (string) $file->url ) . '" target="_blank" rel="noopener">' . esc_html( (string) $file->filename ) . '</a>'
					: esc_html( (string) $file->filename ) )
					. ' <span class="description">' . esc_html( size_format( (int) $file->size ) ) . '</span></li>';
			}
			$html .= '</ul>';
		}

		if ( $is_sent ) {
			return $html; // Replying to your own outgoing copy makes no sense.
		}

		$html .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:860px;margin-top:1em">'
			. '<input type="hidden" name="action" value="mailkite_mailboxes_reply" />'
			. '<input type="hidden" name="uid" value="' . esc_attr( (string) $uid ) . '" />'
			. '<input type="hidden" name="to" value="' . esc_attr( (string) $row->from_addr ) . '" />'
			. '<input type="hidden" name="subject" value="' . esc_attr( $subject ) . '" />'
			. '<input type="hidden" name="message_id" value="' . esc_attr( (string) $row->thread_id ) . '" />'
			. '<input type="hidden" name="redirect_to" value="' . esc_attr( $back ) . '" />'
			. wp_nonce_field( 'mailkite_mailboxes_reply', '_wpnonce', true, false )
			. '<h2>' . esc_html__( 'Reply', 'mailkite-mailboxes' ) . '</h2>'
			. '<textarea name="body" rows="6" class="large-text" required></textarea>'
			. '<p><button type="submit" class="button button-primary">' . esc_html__( 'Send reply', 'mailkite-mailboxes' ) . '</button></p>'
			. '</form>';

		return $html;
	}

	/**
	 * Copy anything missing from MailKite into the local store for one user.
	 *
	 * There is no button for this: the webhook is the fast path, the scheduled reconcile
	 * in Live is the repair, and a mailbox that needs a human to press Sync is a mailbox
	 * that is quietly wrong for everyone who never thinks to press it.
	 *
	 * @param int $user_id The mailbox holder.
	 * @return int|\WP_Error Number of messages stored.
	 */
	public static function sync_user( int $user_id ) {
		$address = Manager::address( $user_id );
		$secret  = Manager::secret( $user_id );
		if ( '' === $address || '' === $secret ) {
			return new \WP_Error( 'no_mailbox', __( 'No mailbox for this user.', 'mailkite-mailboxes' ) );
		}

		$result = Client::list_messages( $secret, $address );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$added = 0;
		foreach ( (array) ( $result['messages'] ?? [] ) as $m ) {
			$message_id = 'mk-uid-' . $address . '-' . (int) ( $m['uid'] ?? 0 );
			if ( \MailKite\Smtp\Log\Store::exists( $message_id ) ) {
				continue;
			}
			$raw    = Client::raw( $secret, $address, (int) $m['uid'] );
			$parsed = is_wp_error( $raw ) ? [
				'text' => '',
				'html' => '',
			] : Mime::parse( $raw );

			\MailKite\Smtp\Log\Store::insert(
				[
					'created_at'    => gmdate( 'Y-m-d H:i:s', (int) strtotime( (string) ( $m['internaldate'] ?? 'now' ) ) ),
					'mail_to'       => (string) ( $m['to_addr'] ?? $address ),
					'from_addr'     => (string) ( $m['from_addr'] ?? '' ),
					'subject'       => (string) ( $m['subject'] ?? '' ),
					'mailer'        => 'inbound',
					'direction'     => 'inbound',
					'status'        => 'received',
					'redacted'      => 0,
					'owner_user_id' => $user_id,
					'message_id'    => $message_id,
					'seen'          => str_contains( (string) ( $m['flags'] ?? '' ), 'Seen' ) ? 1 : 0,
				],
				(string) ( $parsed['text'] ?? '' ),
				(string) ( $parsed['html'] ?? '' )
			);
			++$added;
		}

		return $added;
	}

	/**
	 * A blank message from this mailbox.
	 *
	 * @param string $address The user's own address (shown as the immutable sender).
	 * @return string
	 */
	private function render_compose( string $address ): string {
		$back = remove_query_arg( 'compose' );

		return '<p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Back to the inbox', 'mailkite-mailboxes' ) . '</a></p>'
			. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:860px">'
			. '<input type="hidden" name="action" value="mailkite_mailboxes_compose" />'
			. '<input type="hidden" name="redirect_to" value="' . esc_attr( $back ) . '" />'
			. wp_nonce_field( 'mailkite_mailboxes_compose', '_wpnonce', true, false )
			. '<table class="form-table" role="presentation"><tbody>'
			. '<tr><th scope="row">' . esc_html__( 'From', 'mailkite-mailboxes' ) . '</th>'
				. '<td><code>' . esc_html( $address ) . '</code></td></tr>'
			. '<tr><th scope="row"><label for="mk-compose-to">' . esc_html__( 'To', 'mailkite-mailboxes' ) . '</label></th>'
				. '<td><input type="text" class="regular-text" id="mk-compose-to" name="to" required placeholder="someone@example.com" />'
				. '<p class="description">' . esc_html__( 'Separate several addresses with commas.', 'mailkite-mailboxes' ) . '</p></td></tr>'
			. '<tr><th scope="row"><label for="mk-compose-subject">' . esc_html__( 'Subject', 'mailkite-mailboxes' ) . '</label></th>'
				. '<td><input type="text" class="regular-text" id="mk-compose-subject" name="subject" /></td></tr>'
			. '</tbody></table>'
			. '<textarea name="body" rows="10" class="large-text" required aria-label="' . esc_attr__( 'Message', 'mailkite-mailboxes' ) . '"></textarea>'
			. '<p><button type="submit" class="button button-primary">' . esc_html__( 'Send', 'mailkite-mailboxes' ) . '</button></p>'
			. '</form>';
	}

	/**
	 * Send a new message as the user's own address (admin-post).
	 */
	public function handle_compose(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Permission denied.', 'mailkite-mailboxes' ) );
		}
		check_admin_referer( 'mailkite_mailboxes_compose' );

		$user_id = get_current_user_id();
		$address = Manager::address( $user_id );
		$raw_to  = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';
		$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$body    = isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '';
		$back    = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : admin_url( 'admin.php?page=mailkite-inbox' );

		if ( '' === $address ) {
			$this->redirect( $back, __( 'You do not have a mailbox.', 'mailkite-mailboxes' ) );
		}

		// Every recipient must be a real address: one typo should be a message you can
		// fix, not a silent partial send.
		$recipients = [];
		foreach ( explode( ',', $raw_to ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( '' === $candidate ) {
				continue;
			}
			if ( ! is_email( $candidate ) ) {
				/* translators: %s: the address that failed validation. */
				$this->redirect( $back, sprintf( __( 'That does not look like an email address: %s', 'mailkite-mailboxes' ), $candidate ) );
			}
			$recipients[] = $candidate;
		}
		if ( ! $recipients || '' === $body ) {
			$this->redirect( $back, __( 'A recipient and a message are required.', 'mailkite-mailboxes' ) );
		}
		if ( ! Manager::consume_send_quota( $user_id ) ) {
			$this->redirect( $back, __( 'You have reached your daily send limit.', 'mailkite-mailboxes' ) );
		}

		$error = $this->send_as_mailbox(
			$recipients,
			'' !== $subject ? $subject : __( '(no subject)', 'mailkite-mailboxes' ),
			$body,
			[ 'From: ' . $address ] // Forced — a user can only send as their own address.
		);

		$this->redirect( '' === $error ? add_query_arg( 'mailkite_sent', '1', $back ) : $back, $error );
	}

	/**
	 * Send a reply as the user's own address (admin-post).
	 */
	public function handle_reply(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Permission denied.', 'mailkite-mailboxes' ) );
		}
		check_admin_referer( 'mailkite_mailboxes_reply' );

		$user_id = get_current_user_id();
		$address = Manager::address( $user_id );
		$to      = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';
		$subject = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$body    = isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '';
		$msg_id  = isset( $_POST['message_id'] ) ? sanitize_text_field( wp_unslash( $_POST['message_id'] ) ) : '';
		$back    = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : admin_url( 'admin.php?page=mailkite-inbox' );

		if ( '' === $address ) {
			$this->redirect( $back, __( 'You do not have a mailbox.', 'mailkite-mailboxes' ) );
		}
		if ( '' === $to || '' === $body ) {
			$this->redirect( $back, __( 'A recipient and a message are required.', 'mailkite-mailboxes' ) );
		}
		if ( ! Manager::consume_send_quota( $user_id ) ) {
			$this->redirect( $back, __( 'You have reached your daily send limit.', 'mailkite-mailboxes' ) );
		}

		$headers = [ 'From: ' . $address ]; // Forced — a user can only send as their own address.
		if ( '' !== $msg_id ) {
			$headers[] = 'In-Reply-To: ' . $msg_id;
			$headers[] = 'References: ' . $msg_id;
		}
		$error = $this->send_as_mailbox(
			$to,
			str_starts_with( strtolower( $subject ), 're:' ) ? $subject : 'Re: ' . $subject,
			$body,
			$headers
		);

		$this->redirect( '' === $error ? add_query_arg( 'mailkite_sent', '1', $back ) : $back, $error );
	}

	/**
	 * Send one message as this mailbox, with failover OFF and the real reason captured.
	 *
	 * Personal mail must not silently reroute: if MailKite refuses (say the address sits
	 * on a receive-only domain), the writer needs to see that, not a "sent" that went to
	 * a local PHP mail() sink.
	 *
	 * @param string[]|string $to      Recipients.
	 * @param string          $subject Subject.
	 * @param string          $body    Body.
	 * @param string[]        $headers Headers (From is already forced by the caller).
	 * @return string '' on success, else the failure reason.
	 */
	private function send_as_mailbox( $to, string $subject, string $body, array $headers ): string {
		$reason = '';
		$no_fallback = static fn(): bool => false;
		$capture     = static function ( $error ) use ( &$reason ): void {
			$reason = $error instanceof \WP_Error ? $error->get_error_message() : '';
		};

		add_filter( 'mailkite_smtp_fallback_enabled', $no_fallback, 99 );
		add_action( 'wp_mail_failed', $capture );
		$sent = wp_mail( $to, $subject, $body, $headers );
		remove_action( 'wp_mail_failed', $capture );
		remove_filter( 'mailkite_smtp_fallback_enabled', $no_fallback, 99 );

		if ( $sent ) {
			return '';
		}

		return '' !== $reason ? $reason : __( 'The message could not be sent — check the email log.', 'mailkite-mailboxes' );
	}

	/**
	 * Redirect back with an optional error.
	 *
	 * @param string $url   Target.
	 * @param string $error Message, or '' on success.
	 * @return never
	 */
	private function redirect( string $url, string $error ): void {
		if ( '' !== $error ) {
			$url = add_query_arg( 'mailkite_error', rawurlencode( $error ), $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render an ISO timestamp in the site's timezone and format.
	 *
	 * @param string $iso ISO-8601 timestamp.
	 */
	private function local_time( string $iso ): string {
		$ts = strtotime( $iso );

		return $ts ? (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : $iso;
	}
}
