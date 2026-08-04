<?php
/**
 * Mailboxes admin: the settings tab and the per-user credentials screen.
 *
 * @package MailKite\Mailboxes
 */

namespace MailKite\Mailboxes;

use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Admin surfaces for user mailboxes:
 *  - Settings → Mailboxes: the switches, the domain, the reserved list, who holds what;
 *  - Profile → Your email address: the user's own address, IMAP settings and API bearer.
 */
final class Admin {

	/**
	 * Hook everything.
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ], 20 );
		add_action( 'admin_post_mailkite_mailboxes_save', [ $this, 'handle_save' ] );
		add_action( 'admin_post_mailkite_mailboxes_claim', [ $this, 'handle_claim' ] );
		add_action( 'admin_post_mailkite_mailboxes_release', [ $this, 'handle_release' ] );
		add_action( 'admin_post_mailkite_mailboxes_regenerate', [ $this, 'handle_regenerate' ] );
		add_action( 'admin_post_mailkite_mailboxes_move', [ $this, 'handle_move' ] );
		add_action( 'admin_post_mailkite_mailboxes_rename', [ $this, 'handle_rename' ] );

		add_action( 'show_user_profile', [ $this, 'render_profile' ] );
		add_action( 'edit_user_profile', [ $this, 'render_profile' ] );

		// A deleted user must not keep a live mail credential.
		add_action( 'delete_user', [ Manager::class, 'release' ] );

		// Lazy auto-assignment: no bulk provisioning, no surprise API storm.
		add_action( 'admin_init', [ $this, 'maybe_auto_assign' ] );
	}

	/**
	 * Auto-assign the current user's address when the admin enabled that.
	 */
	public function maybe_auto_assign(): void {
		if ( is_user_logged_in() ) {
			Manager::maybe_auto_assign( get_current_user_id() );
		}
	}

	/**
	 * Mailboxes settings live as a submenu of MailKite SMTP — one place for
	 * everything email, without this add-on editing the parent's tab strip.
	 */
	public function add_menu(): void {
		// Mailbox actions need their own page: WordPress renders profile-screen hooks
		// INSIDE profile.php's <form>, and a nested form is invalid HTML — browsers drop
		// it, so every button there submitted the profile form instead of ours.
		add_submenu_page(
			'mailkite-inbox',
			__( 'Your address', 'mailkite-mailboxes' ),
			__( 'Your address', 'mailkite-mailboxes' ),
			'read',
			'mailkite-mailbox',
			[ $this, 'render_my_mailbox_page' ]
		);
		add_submenu_page(
			'mailkite-smtp',
			__( 'Mailboxes', 'mailkite-mailboxes' ),
			__( 'Mailboxes', 'mailkite-mailboxes' ),
			'manage_options',
			'mailkite-mailboxes',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Settings screen.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mailkite-mailboxes' ) );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'MailKite Mailboxes', 'mailkite-mailboxes' ) . '</h1>';
		$this->render_settings_tab();
		echo '</div>';
	}

	/**
	 * Save the mailbox policy (admin-post).
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'mailkite-mailboxes' ) );
		}
		check_admin_referer( 'mailkite_mailboxes_save' );

		$input = [
			'enabled'       => isset( $_POST['enabled'] ),
			'auto_assign'   => isset( $_POST['auto_assign'] ),
			'self_register' => isset( $_POST['self_register'] ),
			'roles'         => isset( $_POST['roles'] ) && is_array( $_POST['roles'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['roles'] ) ) : [],
		];
		foreach ( [ 'domain', 'reserved', 'send_limit' ] as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$input[ $field ] = wp_unslash( $_POST[ $field ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitized in Settings::update().
			}
		}
		Settings::update( $input );

		wp_safe_redirect(
			add_query_arg(
				[
					'page'    => 'mailkite-mailboxes',
					'updated' => '1',
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * The settings form + who-has-what table.
	 */
	private function render_settings_tab(): void {
		$s       = Settings::all();
		$api_key = (string) \MailKite\Smtp\Options::get( 'api_key' );
		$domains = \MailKite\Smtp\Inbound::list_domains();
		$roles   = wp_roles()->get_names();
		?>
		<p style="margin-top:1em"><?php esc_html_e( 'Give WordPress users a real email address on your domain. Each mailbox works in Apple Mail, Thunderbird or any IMAP client, and in the Inbox screen here. Everything is off until you switch it on.', 'mailkite-mailboxes' ); ?></p>

		<?php if ( '' === $api_key ) : ?>
			<div class="notice notice-info" style="padding:12px"><p style="margin:0">
				<?php esc_html_e( 'Connect a MailKite account first —', 'mailkite-mailboxes' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=mailkite-mailboxes' ) ); ?>"><?php esc_html_e( 'go to Settings', 'mailkite-mailboxes' ); ?></a>
			</p></div>
			<?php return; ?>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mailkite_mailboxes_save" />
			<?php wp_nonce_field( 'mailkite_mailboxes_save' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'User mailboxes', 'mailkite-mailboxes' ); ?></th>
					<td><label><input type="checkbox" name="enabled" <?php checked( (bool) $s['enabled'] ); ?> /> <?php esc_html_e( 'Enable mailboxes for this site', 'mailkite-mailboxes' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="mk-mb-domain"><?php esc_html_e( 'Mailbox domain', 'mailkite-mailboxes' ); ?></label></th>
					<td>
						<?php if ( is_array( $domains ) && $domains ) : ?>
							<select name="domain" id="mk-mb-domain">
								<option value=""><?php esc_html_e( '— choose —', 'mailkite-mailboxes' ); ?></option>
								<?php foreach ( $domains as $d ) : ?>
									<option value="<?php echo esc_attr( $d['domain'] ); ?>" <?php selected( $s['domain'], $d['domain'] ); ?>><?php echo esc_html( $d['domain'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Addresses are created on this domain. Inbound mail for it must reach MailKite (its MX records verified).', 'mailkite-mailboxes' ); ?></p>
						<?php else : ?>
							<input type="text" name="domain" id="mk-mb-domain" class="regular-text" value="<?php echo esc_attr( (string) $s['domain'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Could not list your domains right now — type the domain name.', 'mailkite-mailboxes' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Assignment', 'mailkite-mailboxes' ); ?></th>
					<td>
						<label><input type="checkbox" name="auto_assign" <?php checked( (bool) $s['auto_assign'] ); ?> />
							<?php esc_html_e( 'Give every allowed user {username}@domain automatically', 'mailkite-mailboxes' ); ?></label><br/>
						<label><input type="checkbox" name="self_register" <?php checked( (bool) $s['self_register'] ); ?> />
							<?php esc_html_e( 'Let users choose their own address', 'mailkite-mailboxes' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Roles allowed', 'mailkite-mailboxes' ); ?></th>
					<td>
						<?php foreach ( $roles as $slug => $label ) : ?>
							<label style="margin-right:1em"><input type="checkbox" name="roles[]" value="<?php echo esc_attr( $slug ); ?>"
								<?php checked( in_array( $slug, (array) $s['roles'], true ) ); ?> /> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mk-mb-reserved"><?php esc_html_e( 'Reserved addresses', 'mailkite-mailboxes' ); ?></label></th>
					<td>
						<textarea name="reserved" id="mk-mb-reserved" class="large-text code" rows="3"><?php echo esc_textarea( (string) $s['reserved'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Local parts nobody may claim (comma or space separated). Role addresses like postmaster and billing belong here.', 'mailkite-mailboxes' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mk-mb-limit"><?php esc_html_e( 'Send limit', 'mailkite-mailboxes' ); ?></label></th>
					<td>
						<input type="number" min="0" id="mk-mb-limit" name="send_limit" value="<?php echo esc_attr( (string) $s['send_limit'] ); ?>" style="width:6em" />
						<?php esc_html_e( 'messages per user per day (0 = unlimited)', 'mailkite-mailboxes' ); ?>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Mailbox Settings', 'mailkite-mailboxes' ) ); ?>
		</form>

		<h2><?php esc_html_e( 'Who has a mailbox', 'mailkite-mailboxes' ); ?></h2>
		<?php $holders = Manager::all_holders(); ?>
		<table class="widefat striped" style="max-width:760px">
			<thead><tr>
				<th><?php esc_html_e( 'User', 'mailkite-mailboxes' ); ?></th>
				<th><?php esc_html_e( 'Address', 'mailkite-mailboxes' ); ?></th>
				<th></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $holders ) : ?>
				<tr><td colspan="3"><?php esc_html_e( 'Nobody yet. Users claim an address from their profile screen.', 'mailkite-mailboxes' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $holders as $h ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( get_edit_user_link( $h['user_id'] ) ); ?>"><?php echo esc_html( $h['login'] ); ?></a></td>
					<td><code><?php echo esc_html( $h['address'] ); ?></code></td>
					<td style="text-align:right">
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="mailkite_mailboxes_release" />
							<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $h['user_id'] ); ?>" />
							<?php wp_nonce_field( 'mailkite_mailboxes_release' ); ?>
							<button type="submit" class="button button-small"><?php esc_html_e( 'Revoke', 'mailkite-mailboxes' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * "Your address" — claim, rename, move, regenerate, delete for the current user.
	 */
	public function render_my_mailbox_page(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Permission denied.', 'mailkite-mailboxes' ) );
		}
		$user = wp_get_current_user();

		echo '<div class="wrap"><h1>' . esc_html__( 'Your email address', 'mailkite-mailboxes' ) . '</h1>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only message from our own redirect.
		if ( isset( $_GET['mailkite_error'] ) ) {
			echo '<div class="notice notice-error"><p>'
				. esc_html( sanitize_text_field( wp_unslash( $_GET['mailkite_error'] ) ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				. '</p></div>';
		}
		$this->render_mailbox_controls( $user );
		echo '</div>';
	}

	/**
	 * Profile screen section: the user's address, IMAP settings and API bearer.
	 *
	 * @param WP_User $user The profile being shown.
	 */
	public function render_profile( WP_User $user ): void {
		if ( ! Manager::enabled() ) {
			return;
		}
		$is_self = get_current_user_id() === $user->ID;
		if ( ! $is_self && ! current_user_can( 'edit_users' ) ) {
			return;
		}
		$address = Manager::address( $user->ID );

		// Read-only by design. This markup is printed INSIDE profile.php's own <form>,
		// and a nested form is invalid HTML — the browser drops it and the button ends up
		// submitting the profile form. Every action lives on the "Your address" page.
		?>
		<h2><?php esc_html_e( 'MailKite email address', 'mailkite-mailboxes' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Address', 'mailkite-mailboxes' ); ?></th>
				<td>
					<?php if ( '' === $address ) : ?>
						<em><?php esc_html_e( 'No mailbox yet.', 'mailkite-mailboxes' ); ?></em>
					<?php else : ?>
						<code style="user-select:all"><?php echo esc_html( $address ); ?></code>
					<?php endif; ?>
					<?php if ( $is_self ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=mailkite-mailbox' ) ); ?>" class="button button-small" style="margin-left:8px">
							<?php echo '' === $address ? esc_html__( 'Create a mailbox', 'mailkite-mailboxes' ) : esc_html__( 'Manage your address', 'mailkite-mailboxes' ); ?>
						</a>
					<?php elseif ( '' !== $address ) : ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=mailkite-mailboxes' ) ); ?>" class="button button-small" style="margin-left:8px"><?php esc_html_e( 'Manage mailboxes', 'mailkite-mailboxes' ); ?></a>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Every mailbox control for one user, in standalone forms (never inside another form).
	 *
	 * @param WP_User $user The mailbox holder.
	 */
	private function render_mailbox_controls( WP_User $user ): void {
		if ( ! Manager::enabled() ) {
			echo '<p>' . esc_html__( 'Mailboxes are not enabled on this site yet.', 'mailkite-mailboxes' ) . '</p>';

			return;
		}
		$address  = Manager::address( $user->ID );
		$may_pick = Settings::get( 'self_register' ) || current_user_can( 'edit_users' );

		if ( '' === $address ) {
			if ( ! Manager::role_allowed( $user ) ) {
				echo '<p>' . esc_html__( 'Your role cannot have a mailbox on this site.', 'mailkite-mailboxes' ) . '</p>';

				return;
			}
			if ( ! $may_pick ) {
				echo '<p>' . esc_html__( 'You do not have a mailbox yet — an administrator can create one for you.', 'mailkite-mailboxes' ) . '</p>';

				return;
			}
			?>
			<p><?php esc_html_e( 'Pick the part before the @ — this becomes your address on this site.', 'mailkite-mailboxes' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
				<input type="hidden" name="action" value="mailkite_mailboxes_claim" />
				<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user->ID ); ?>" />
				<?php wp_nonce_field( 'mailkite_mailboxes_claim' ); ?>
				<input type="text" name="local" value="<?php echo esc_attr( Manager::normalize_local( $user->user_login ) ); ?>" class="regular-text" style="max-width:14em" />
				<span>@<?php echo esc_html( Manager::domain() ); ?></span>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Create mailbox', 'mailkite-mailboxes' ); ?></button>
			</form>
			<?php
			return;
		}
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Address', 'mailkite-mailboxes' ); ?></th>
				<td>
					<code style="user-select:all"><?php echo esc_html( $address ); ?></code>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mailkite-inbox' ) ); ?>" class="button button-small" style="margin-left:8px"><?php esc_html_e( 'Open inbox', 'mailkite-mailboxes' ); ?></a>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Mail app settings', 'mailkite-mailboxes' ); ?></th>
				<td>
					<table class="widefat striped" style="max-width:560px">
						<tbody>
							<tr><td><?php esc_html_e( 'Incoming (IMAP)', 'mailkite-mailboxes' ); ?></td><td><code>imap.mailkite.dev</code> · <?php esc_html_e( 'port', 'mailkite-mailboxes' ); ?> 993 · SSL/TLS</td></tr>
							<tr><td><?php esc_html_e( 'Outgoing (SMTP)', 'mailkite-mailboxes' ); ?></td><td><code>smtp.mailkite.dev</code> · <?php esc_html_e( 'port', 'mailkite-mailboxes' ); ?> 587 · STARTTLS</td></tr>
							<tr><td><?php esc_html_e( 'Username', 'mailkite-mailboxes' ); ?></td><td><code><?php echo esc_html( $address ); ?></code></td></tr>
							<tr>
								<td><?php esc_html_e( 'Password', 'mailkite-mailboxes' ); ?></td>
								<td>
									<code id="mk-mb-secret" data-secret="<?php echo esc_attr( Manager::secret( $user->ID ) ); ?>">••••••••••••</code>
									<button type="button" class="button button-small" id="mk-mb-reveal"><?php esc_html_e( 'Show', 'mailkite-mailboxes' ); ?></button>
								</td>
							</tr>
						</tbody>
					</table>
					<p class="description"><?php esc_html_e( 'The same password is also an API token for this address.', 'mailkite-mailboxes' ); ?></p>
					<script>
					document.getElementById( 'mk-mb-reveal' ).addEventListener( 'click', function () {
						var el = document.getElementById( 'mk-mb-secret' );
						var shown = el.textContent.indexOf( '•' ) === -1;
						el.textContent = shown ? '••••••••••••' : el.dataset.secret;
						this.textContent = shown ? <?php echo wp_json_encode( __( 'Show', 'mailkite-mailboxes' ) ); ?> : <?php echo wp_json_encode( __( 'Hide', 'mailkite-mailboxes' ) ); ?>;
					} );
					</script>
				</td>
			</tr>
		</table>

		<?php if ( Manager::is_off_domain( $user->ID ) ) : ?>
			<div class="notice notice-warning" style="padding:10px 12px">
				<p style="margin:0 0 6px">
					<?php
					printf(
						/* translators: %s: the domain mailboxes now use. */
						esc_html__( 'This address is on an older domain. Mail sent from it still goes out as that domain — mailboxes now use %s.', 'mailkite-mailboxes' ),
						'<code>' . esc_html( Manager::domain() ) . '</code>'
					);
					?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0">
					<input type="hidden" name="action" value="mailkite_mailboxes_move" />
					<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user->ID ); ?>" />
					<?php wp_nonce_field( 'mailkite_mailboxes_move' ); ?>
					<button type="submit" class="button">
						<?php
						/* translators: %s: the domain mailboxes now use. */
						printf( esc_html__( 'Move to %s', 'mailkite-mailboxes' ), esc_html( Manager::domain() ) );
						?>
					</button>
				</form>
			</div>
		<?php endif; ?>

		<?php if ( $may_pick ) : ?>
			<h2><?php esc_html_e( 'Change your address', 'mailkite-mailboxes' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
				<input type="hidden" name="action" value="mailkite_mailboxes_rename" />
				<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user->ID ); ?>" />
				<?php wp_nonce_field( 'mailkite_mailboxes_rename' ); ?>
				<input type="text" name="local" value="<?php echo esc_attr( substr( $address, 0, (int) strpos( $address, '@' ) ) ); ?>" class="regular-text" style="max-width:14em" aria-label="<?php esc_attr_e( 'New address', 'mailkite-mailboxes' ); ?>" />
				<span>@<?php echo esc_html( Manager::domain() ); ?></span>
				<button type="submit" class="button"><?php esc_html_e( 'Change address', 'mailkite-mailboxes' ); ?></button>
			</form>
			<p class="description"><?php esc_html_e( 'Mail to the old address stops arriving and the password is replaced — update any mail app afterwards.', 'mailkite-mailboxes' ); ?></p>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Password and removal', 'mailkite-mailboxes' ); ?></h2>
		<div style="display:flex;gap:8px;flex-wrap:wrap">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0">
				<input type="hidden" name="action" value="mailkite_mailboxes_regenerate" />
				<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user->ID ); ?>" />
				<?php wp_nonce_field( 'mailkite_mailboxes_regenerate' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Regenerate password', 'mailkite-mailboxes' ); ?></button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0">
				<input type="hidden" name="action" value="mailkite_mailboxes_release" />
				<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $user->ID ); ?>" />
				<?php wp_nonce_field( 'mailkite_mailboxes_release' ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'Delete mailbox', 'mailkite-mailboxes' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Claim an address (admin-post): self-service, or an admin acting for a user.
	 */
	public function handle_claim(): void {
		$user_id = $this->guard( 'mailkite_mailboxes_claim' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() called check_admin_referer() on the line above.
		$local = isset( $_POST['local'] ) ? sanitize_text_field( wp_unslash( $_POST['local'] ) ) : '';

		if ( get_current_user_id() !== $user_id && ! current_user_can( 'edit_users' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'mailkite-mailboxes' ) );
		}
		if ( get_current_user_id() === $user_id && ! Settings::get( 'self_register' ) && ! current_user_can( 'edit_users' ) ) {
			wp_die( esc_html__( 'Choosing your own address is disabled on this site.', 'mailkite-mailboxes' ) );
		}

		$result = Manager::claim( $user_id, $local );
		$this->back( $user_id, is_wp_error( $result ) ? $result->get_error_message() : '' );
	}

	/**
	 * Release an address (admin-post): the holder or an admin.
	 */
	public function handle_release(): void {
		$user_id = $this->guard( 'mailkite_mailboxes_release' );
		if ( get_current_user_id() !== $user_id && ! current_user_can( 'edit_users' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'mailkite-mailboxes' ) );
		}
		Manager::release( $user_id );
		$this->back( $user_id, '' );
	}

	/**
	 * Mint a fresh password for your own mailbox (admin-post).
	 */
	public function handle_regenerate(): void {
		$user_id = $this->guard( 'mailkite_mailboxes_regenerate' );
		if ( get_current_user_id() !== $user_id ) {
			wp_die( esc_html__( 'You can only regenerate your own password.', 'mailkite-mailboxes' ) );
		}
		$result = Manager::regenerate( $user_id );
		$this->back( $user_id, is_wp_error( $result ) ? $result->get_error_message() : '' );
	}

	/**
	 * Change the local part of a mailbox (admin-post).
	 */
	public function handle_rename(): void {
		$user_id = $this->guard( 'mailkite_mailboxes_rename' );
		if ( get_current_user_id() !== $user_id && ! current_user_can( 'edit_users' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'mailkite-mailboxes' ) );
		}
		// Users may only rename themselves when the site lets them choose addresses;
		// an administrator can always fix someone's address.
		if ( get_current_user_id() === $user_id && ! Settings::get( 'self_register' ) && ! current_user_can( 'edit_users' ) ) {
			wp_die( esc_html__( 'Choosing your own address is disabled on this site.', 'mailkite-mailboxes' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() called check_admin_referer() at the top of this handler.
		$local  = isset( $_POST['local'] ) ? sanitize_text_field( wp_unslash( $_POST['local'] ) ) : '';
		$result = Manager::rename( $user_id, $local );
		$this->back( $user_id, is_wp_error( $result ) ? $result->get_error_message() : '' );
	}

	/**
	 * Move a mailbox to the current domain (admin-post).
	 */
	public function handle_move(): void {
		$user_id = $this->guard( 'mailkite_mailboxes_move' );
		if ( get_current_user_id() !== $user_id && ! current_user_can( 'edit_users' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'mailkite-mailboxes' ) );
		}
		$result = Manager::move( $user_id );
		$this->back( $user_id, is_wp_error( $result ) ? $result->get_error_message() : '' );
	}

	/**
	 * Shared nonce/login guard; returns the target user id.
	 *
	 * @param string $action Nonce action.
	 */
	private function guard( string $action ): int {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Permission denied.', 'mailkite-mailboxes' ) );
		}
		check_admin_referer( $action );

		return isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : get_current_user_id();
	}

	/**
	 * Back to wherever the action came from, with an optional error.
	 *
	 * @param int    $user_id Target user.
	 * @param string $error   Message, or '' on success.
	 * @return never
	 */
	private function back( int $user_id, string $error ): void {
		$referer = wp_get_referer();
		$url     = $referer ? $referer : get_edit_user_link( $user_id );
		if ( '' !== $error ) {
			$url = add_query_arg( 'mailkite_error', rawurlencode( $error ), $url );
		}
		wp_safe_redirect( $url );
		exit;
	}
}
