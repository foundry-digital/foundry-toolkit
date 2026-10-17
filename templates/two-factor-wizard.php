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
 * @var string                                                           $logo_url
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
	<title><?php esc_html_e( 'One quick security step', 'foundry-toolkit' ); ?></title>
	<link rel="icon" type="image/svg+xml" href="<?php echo esc_url( $logo_url ); ?>">
	<?php wp_print_styles( array( 'buttons', 'common', 'forms' ) ); ?>
	<style>
		body.foundry-toolkit-2fa { background: #f0f0f1; margin: 0; height: auto; }
		.foundry-toolkit-2fa .wrap { max-width: 580px; margin: 40px auto; padding: 0 16px; }
		.foundry-toolkit-2fa .brand { display: block; width: 64px; height: 64px; margin: 20px 0 24px; border-radius: 8px; }
		.foundry-toolkit-2fa .card { max-width: none; padding: 8px 28px 28px; border-top: 4px solid #5634d1; }
		.foundry-toolkit-2fa h1 { font-size: 24px; line-height: 1.3; }
		.foundry-toolkit-2fa h2 { font-size: 16px; margin: 0 0 .5em; }
		.foundry-toolkit-2fa .lead { font-size: 15px; }
		.foundry-toolkit-2fa ol.steps { margin-left: 1.25em; }
		.foundry-toolkit-2fa ol.steps > li { margin-bottom: 1.5em; }
		.foundry-toolkit-2fa ul.apps { list-style: disc; margin: 0 0 1em 1.5em; }
		.foundry-toolkit-2fa #two-factor-qr-code svg { width: 200px; height: 200px; }
		.foundry-toolkit-2fa .key { font-size: 15px; letter-spacing: 1px; word-break: break-all; }
		.foundry-toolkit-2fa .codes { columns: 2; list-style: none; margin: 0 0 1em; padding: 0; font: 16px/1.8 monospace; }
		.foundry-toolkit-2fa .wp-core-ui .button-primary,
		.foundry-toolkit-2fa .button-primary { background: #5634d1; border-color: #5634d1; }
		.foundry-toolkit-2fa .button-primary:hover,
		.foundry-toolkit-2fa .button-primary:focus { background: #4527b0; border-color: #4527b0; }
		.foundry-toolkit-2fa a { color: #5634d1; }
		.foundry-toolkit-2fa .footer { display: flex; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-top: 16px; padding-bottom: 40px; color: #50575e; }
	</style>
</head>
<body class="wp-core-ui foundry-toolkit-2fa">
<div class="wrap">
	<div class="card">
		<img class="brand" src="<?php echo esc_url( $logo_url ); ?>" alt="<?php esc_attr_e( 'Foundry Digital', 'foundry-toolkit' ); ?>" width="64" height="64">
	<?php if ( 'codes' === $state['step'] ) : ?>
		<h1><?php esc_html_e( 'Nearly there: keep your backup codes', 'foundry-toolkit' ); ?></h1>
		<p class="lead"><?php esc_html_e( 'Your authenticator app is set up. Nice work.', 'foundry-toolkit' ); ?></p>
		<p><?php esc_html_e( 'If you lose or replace your phone, each of these codes logs you in once instead of a code from the app. This is the only time they are shown, so download them or write them down and keep them somewhere safe, like your password manager.', 'foundry-toolkit' ); ?></p>
		<ul class="codes">
			<?php foreach ( $state['codes'] as $foundry_toolkit_code ) : ?>
				<li><?php echo esc_html( $foundry_toolkit_code ); ?></li>
			<?php endforeach; ?>
		</ul>
		<p>
			<a class="button" download="<?php echo esc_attr( sanitize_title( $site_name ) . '-backup-codes.txt' ); ?>" href="<?php echo esc_attr( 'data:text/plain;charset=utf-8,' . rawurlencode( $foundry_toolkit_codes_text ) ); ?>"><?php esc_html_e( 'Download the codes', 'foundry-toolkit' ); ?></a>
			<a class="button button-primary" href="<?php echo esc_url( admin_url() ); ?>"><?php esc_html_e( 'I have saved them, take me to the dashboard', 'foundry-toolkit' ); ?></a>
		</p>
	<?php else : ?>
		<h1><?php esc_html_e( 'One quick security step', 'foundry-toolkit' ); ?></h1>
		<p class="lead"><?php esc_html_e( 'We know, two-factor authentication is a bit of a chore. Nobody wakes up excited to type in a six-digit code.', 'foundry-toolkit' ); ?></p>
		<p><?php esc_html_e( 'But a password on its own is a single key to your whole website, and passwords get guessed, reused and leaked all the time. With a code from your phone as well, a stolen password is not enough to get in. It takes about two minutes and you only do it once. Until then, the rest of the site will wait here for you.', 'foundry-toolkit' ); ?></p>
		<?php if ( '' !== $state['error'] ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $state['error'] ); ?></p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( $form_url ); ?>">
			<?php wp_nonce_field( Foundry_Toolkit_Two_Factor::NONCE ); ?>
			<input type="hidden" name="key" value="<?php echo esc_attr( $state['key'] ); ?>">
			<ol class="steps">
				<li>
					<h2><?php esc_html_e( 'Get an authenticator app', 'foundry-toolkit' ); ?></h2>
					<p><?php esc_html_e( 'Already have one? Skip ahead. If not, any of these free apps will do:', 'foundry-toolkit' ); ?></p>
					<ul class="apps">
						<li><?php esc_html_e( 'Google Authenticator (iPhone and Android)', 'foundry-toolkit' ); ?></li>
						<li><?php esc_html_e( 'Microsoft Authenticator (iPhone and Android)', 'foundry-toolkit' ); ?></li>
						<li><?php esc_html_e( 'The Passwords app already on your iPhone or Mac (iOS 18 and macOS Sequoia or later)', 'foundry-toolkit' ); ?></li>
						<li><?php esc_html_e( '1Password or Bitwarden, if you already use one of them', 'foundry-toolkit' ); ?></li>
					</ul>
				</li>
				<li>
					<h2><?php esc_html_e( 'Scan this code with the app', 'foundry-toolkit' ); ?></h2>
					<p><?php esc_html_e( 'In the app, add an account and point your camera at this code.', 'foundry-toolkit' ); ?></p>
					<p id="two-factor-qr-code"><a href="<?php echo esc_url( $qr_url, array( 'otpauth' ) ); ?>"><?php esc_html_e( 'Loading the QR code...', 'foundry-toolkit' ); ?></a></p>
					<p><?php esc_html_e( 'Cannot scan it? Type this key into the app instead:', 'foundry-toolkit' ); ?></p>
					<p><code class="key"><?php echo esc_html( $state['key'] ); ?></code></p>
				</li>
				<li>
					<h2><?php esc_html_e( 'Enter the code the app shows', 'foundry-toolkit' ); ?></h2>
					<p><label for="foundry-toolkit-2fa-code"><?php esc_html_e( 'The six-digit code for this site. It changes every 30 seconds, so use the one showing now.', 'foundry-toolkit' ); ?></label></p>
					<p>
						<input type="text" id="foundry-toolkit-2fa-code" name="code" class="regular-text" inputmode="numeric" pattern="[0-9 ]*" autocomplete="one-time-code" required>
						<?php submit_button( __( 'Confirm', 'foundry-toolkit' ), 'primary', 'submit', false ); ?>
					</p>
				</li>
			</ol>
		</form>
	<?php endif; ?>
	</div>
	<div class="footer">
		<span><?php esc_html_e( 'Stuck?', 'foundry-toolkit' ); ?> <a href="mailto:contact@foundrydigital.com.au"><?php esc_html_e( 'Get in touch with Foundry Digital and we will sort it out.', 'foundry-toolkit' ); ?></a></span>
		<a href="<?php echo esc_url( $logout_url ); ?>"><?php esc_html_e( 'Log out', 'foundry-toolkit' ); ?></a>
	</div>
</div>
<?php
if ( 'setup' === $state['step'] ) {
	wp_print_scripts( 'two-factor-totp-qrcode' );
}
?>
</body>
</html>
