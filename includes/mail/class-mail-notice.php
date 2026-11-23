<?php
/**
 * Admin banner for visitor-mail limits.
 *
 * @package BlockForms
 */

namespace BlockForms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Mail_Notice
 *
 * Shown on BlockForms screens (screen ID contains "blockforms") for 24 hours after a visitor-mail cap was
 * hit (Mail_Limits). Dismissible per user. Names the cap and links to Settings, Email.
 */
class Mail_Notice {

	/**
	 * User meta key: when the user dismissed the banner (unix time).
	 */
	const META = 'blockforms_mail_cap_dismissed';

	/**
	 * admin-post action and nonce action of the dismiss link.
	 */
	const ACTION = 'blockforms_dismiss_mail_notice';

	/**
	 * Hook the banner and the dismiss handler.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_notices', [ __CLASS__, 'render' ] );
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'dismiss' ] );
	}

	/**
	 * Print the banner when it applies.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! self::on_blockforms_screen() || ! Access::can( 'blockforms_manage_settings' ) ) {
			return;
		}
		$state = Mail_Limits::notice_state();
		if ( null === $state || Clock::now() - $state['time'] > DAY_IN_SECONDS ) {
			return;
		}
		if ( (int) get_user_meta( get_current_user_id(), self::META, true ) >= $state['time'] ) {
			return;
		}

		$cap = 'hourly' === $state['cap']
			? sprintf(
				/* translators: %d: mails per hour. */
				_n( 'the limit of %d mail per hour for the whole site', 'the limit of %d mails per hour for the whole site', $state['limit'], 'blockforms' ),
				$state['limit']
			)
			: sprintf(
				/* translators: %d: mails per day. */
				_n( 'the limit of %d mail per day for one form', 'the limit of %d mails per day for one form', $state['limit'], 'blockforms' ),
				$state['limit']
			);

		$dismiss  = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION );
		$settings = admin_url( 'admin.php?page=blockforms-settings' );

		echo '<div class="notice notice-warning"><p>';
		echo esc_html(
			sprintf(
				/* translators: %s: the limit, for example "the limit of 100 mails per day for one form". */
				__( 'BlockForms did not send some mail to addresses that visitors typed in, because it reached %s. The entries were saved.', 'blockforms' ),
				$cap
			)
		);
		echo ' <a href="' . esc_url( $settings ) . '">' . esc_html__( 'Review the email settings', 'blockforms' ) . '</a>';
		echo ' | <a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Dismiss', 'blockforms' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Handle the dismiss link.
	 *
	 * @return void
	 */
	public static function dismiss() {
		check_admin_referer( self::ACTION );
		if ( ! Access::can( 'blockforms_manage_settings' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'blockforms' ), '', [ 'response' => 403 ] );
		}
		update_user_meta( get_current_user_id(), self::META, Clock::now() );

		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url() );
		exit;
	}

	/**
	 * Whether the current admin screen is one of BlockForms'.
	 *
	 * @return bool
	 */
	private static function on_blockforms_screen() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		return $screen instanceof \WP_Screen && false !== strpos( (string) $screen->id, 'blockforms' );
	}
}
