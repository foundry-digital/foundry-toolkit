<?php
/**
 * The two-factor setup wizard (ADR 0035): scan, confirm, save backup codes.
 * Printed by Foundry_Toolkit_Two_Factor::render(), which sets the variables.
 *
 * @package FoundryToolkit
 *
 * @var WP_User                                                          $user
 * @var array{step: string, key: string, error: string, codes: string[]} $state
 * @var string                                                           $qr_url
 * @var string                                                           $form_url
 * @var string                                                           $logout_url
 * @var string                                                           $site_name
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$foundry_toolkit_codes_text = implode( "\n", $state['codes'] ) . "\n";
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php esc_html_e( 'Set up two-factor authentication', 'foundry-toolkit' ); ?></title>
	<?php wp_print_styles( array( 'buttons', 'common', 'forms' ) ); ?>
	<style>
		body.foundry-toolkit-2fa { background: #f0f0f1; margin: 0; height: auto; }
		.foundry-toolkit-2fa .wrap { max-width: 560px; margin: 48px auto; padding: 0 16px; }
		.foundry-toolkit-2fa .card { max-width: none; padding: 8px 24px 24px; }
		.foundry-toolkit-2fa ol { margin-left: 1.5em; }
		.foundry-toolkit-2fa ol li { margin-bottom: 1.25em; }
		.foundry-toolkit-2fa #two-factor-qr-code svg { width: 200px; height: 200px; }
		.foundry-toolkit-2fa .key { font-size: 15px; letter-spacing: 1px; word-break: break-all; }
		.foundry-toolkit-2fa .codes { columns: 2; list-style: none; margin: 0 0 1em; padding: 0; font: 16px/1.8 monospace; }
		.foundry-toolkit-2fa .logout { margin-top: 1.5em; }
	</style>
</head>
<body class="wp-core-ui foundry-toolkit-2fa">
<div class="wrap">
	<div class="card">
	<?php if ( 'codes' === $state['step'] ) : ?>
		<h1><?php esc_html_e( 'Step 3: save your backup codes', 'foundry-toolkit' ); ?></h1>
		<p><?php esc_html_e( 'Your authenticator app is set up. If you lose your phone, each of these codes logs you in once instead of a code from the app. This is the only time they are shown, so download them or write them down and keep them somewhere safe.', 'foundry-toolkit' ); ?></p>
		<ul class="codes">
			<?php foreach ( $state['codes'] as $foundry_toolkit_code ) : ?>
				<li><?php echo esc_html( $foundry_toolkit_code ); ?></li>
			<?php endforeach; ?>
		</ul>
		<p>
			<a class="button" download="<?php echo esc_attr( sanitize_title( $site_name ) . '-backup-codes.txt' ); ?>" href="<?php echo esc_attr( 'data:text/plain;charset=utf-8,' . rawurlencode( $foundry_toolkit_codes_text ) ); ?>"><?php esc_html_e( 'Download the codes', 'foundry-toolkit' ); ?></a>
			<a class="button button-primary" href="<?php echo esc_url( admin_url() ); ?>"><?php esc_html_e( 'I have saved them, go to the dashboard', 'foundry-toolkit' ); ?></a>
		</p>
	<?php else : ?>
		<h1><?php esc_html_e( 'Set up two-factor authentication', 'foundry-toolkit' ); ?></h1>
		<p><?php esc_html_e( 'Administrators of this site need an authenticator app to log in, so a stolen password alone is not enough. It takes about two minutes. Until it is done, the rest of the site is locked.', 'foundry-toolkit' ); ?></p>
		<?php if ( '' !== $state['error'] ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $state['error'] ); ?></p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( $form_url ); ?>">
			<?php wp_nonce_field( Foundry_Toolkit_Two_Factor::NONCE ); ?>
			<input type="hidden" name="key" value="<?php echo esc_attr( $state['key'] ); ?>">
			<ol>
				<li>
					<h2><?php esc_html_e( 'Step 1: scan the code', 'foundry-toolkit' ); ?></h2>
					<p><?php esc_html_e( 'Install an authenticator app on your phone if you do not have one: Google Authenticator, Microsoft Authenticator, 1Password or Authy all work. Add an account in the app and scan this code.', 'foundry-toolkit' ); ?></p>
					<p id="two-factor-qr-code"><a href="<?php echo esc_url( $qr_url, array( 'otpauth' ) ); ?>"><?php esc_html_e( 'Loading the QR code...', 'foundry-toolkit' ); ?></a></p>
					<p><?php esc_html_e( 'Cannot scan it? Type this key into the app instead:', 'foundry-toolkit' ); ?></p>
					<p><code class="key"><?php echo esc_html( $state['key'] ); ?></code></p>
				</li>
				<li>
					<h2><?php esc_html_e( 'Step 2: enter a code', 'foundry-toolkit' ); ?></h2>
					<p><label for="foundry-toolkit-2fa-code"><?php esc_html_e( 'The six-digit code your app shows for this site', 'foundry-toolkit' ); ?></label></p>
					<p>
						<input type="text" id="foundry-toolkit-2fa-code" name="code" class="regular-text" inputmode="numeric" pattern="[0-9 ]*" autocomplete="one-time-code" required>
						<?php submit_button( __( 'Confirm', 'foundry-toolkit' ), 'primary', 'submit', false ); ?>
					</p>
				</li>
			</ol>
		</form>
	<?php endif; ?>
	</div>
	<p class="logout"><a href="<?php echo esc_url( $logout_url ); ?>"><?php esc_html_e( 'Log out', 'foundry-toolkit' ); ?></a></p>
</div>
<?php
if ( 'setup' === $state['step'] ) {
	wp_print_scripts( 'two-factor-totp-qrcode' );
}
?>
</body>
</html>
