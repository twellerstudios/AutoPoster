<?php
/**
 * Form handler for the static BEAZER demo (fan club + contact).
 *
 * Lives next to index.html in the demo folder. Emails each submission to
 * $notify and keeps fan club sign-ups in data/fan-club.csv.php: CSV rows
 * behind a PHP guard line, so the file shows nothing if opened in a browser
 * on any server (data/.htaccess blocks it on Apache too). Download it with
 * the host's file manager and delete the first line to open it as a CSV.
 * Redirects back to the page with ?beazer=<status>.
 */

$notify = '{{NOTIFY_EMAIL}}';
$from   = 'no-reply@' . preg_replace( '/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'localhost' );

function bz_clean( $key, $max = 200 ) {
	$v = trim( (string) ( $_POST[ $key ] ?? '' ) );
	$v = str_replace( array( "\r", "\n" ), ' ', $v );
	return mb_substr( strip_tags( $v ), 0, $max );
}

function bz_back( $status, $anchor ) {
	$back = $_SERVER['HTTP_REFERER'] ?? '';
	$host = preg_replace( '/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? '' );
	if ( ! $back || parse_url( $back, PHP_URL_HOST ) !== $host ) {
		$back = './';
	}
	$back = preg_replace( '/[?#].*$/', '', $back );
	header( 'Location: ' . $back . '?beazer=' . $status . '#' . $anchor, true, 303 );
	exit;
}

if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
	bz_back( 'error', 'main' );
}

$action = $_POST['action'] ?? '';
$anchor = 'beazer_join' === $action ? 'join' : 'contact';
if ( ! empty( $_POST['website'] ) ) { // honeypot: bots fill every field
	bz_back( 'beazer_join' === $action ? 'joined' : 'sent', $anchor );
}

$headers = "From: BEAZER website <$from>\r\nContent-Type: text/plain; charset=UTF-8\r\n";

if ( 'beazer_join' === $action ) {
	$name    = bz_clean( 'fan_name' );
	$email   = filter_var( bz_clean( 'fan_email' ), FILTER_VALIDATE_EMAIL );
	$country = bz_clean( 'fan_country' );
	if ( ! $name || ! $email || empty( $_POST['fan_consent'] ) ) {
		bz_back( 'error', $anchor );
	}
	$dir = __DIR__ . '/data';
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0750 );
	}
	if ( ! file_exists( "$dir/.htaccess" ) ) {
		file_put_contents( "$dir/.htaccess", "Require all denied\nDeny from all\n" );
	}
	$row = array_map( function ( $v ) { // neutralise spreadsheet formulas
		return preg_match( '/^[=+\-@]/', $v ) ? "'" . $v : $v;
	}, array( date( 'c' ), $email, $name, $country ) );
	$file = "$dir/fan-club.csv.php";
	$fh   = fopen( $file, 'a' );
	if ( $fh ) {
		flock( $fh, LOCK_EX );
		if ( 0 === filesize( $file ) ) {
			fwrite( $fh, "<?php http_response_code( 404 ); exit; ?>\n" );
			fputcsv( $fh, array( 'joined', 'email', 'name', 'country' ) );
		}
		fputcsv( $fh, $row );
		fclose( $fh );
	}
	@mail( $notify, 'New BEAZER fan club member', "Name: $name\nEmail: $email\nCountry: $country\n", $headers . "Reply-To: $email\r\n" );
	bz_back( 'joined', $anchor );
}

if ( 'beazer_contact' === $action ) {
	$name    = bz_clean( 'c_name' );
	$email   = filter_var( bz_clean( 'c_email' ), FILTER_VALIDATE_EMAIL );
	$topic   = bz_clean( 'c_topic', 40 );
	$message = mb_substr( trim( strip_tags( (string) ( $_POST['c_message'] ?? '' ) ) ), 0, 5000 );
	if ( ! $name || ! $email || ! $message ) {
		bz_back( 'error', $anchor );
	}
	$sent = mail( $notify, "[BEAZER website] $topic enquiry from $name", "Name: $name\nEmail: $email\nTopic: $topic\n\n$message\n", $headers . "Reply-To: $email\r\n" );
	bz_back( $sent ? 'sent' : 'mailfail', $anchor );
}

bz_back( 'error', $anchor );
