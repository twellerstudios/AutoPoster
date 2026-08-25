<?php
/**
 * Exercises the real build_ics() and build_event() against a WordPress-like
 * environment: PHP default timezone pinned to UTC, exactly as wp-settings.php
 * does. Asserts the invite matches the booked wall-clock time.
 */
date_default_timezone_set( 'UTC' );

define( 'ABSPATH', dirname(__DIR__) );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'TWELLER_FLOW_2_TABLE_SESSIONS', 'sessions' );

$OPTIONS = array(
    'tweller_flow_2_packages' => array(
        'mini'    => array( 'name' => 'Mini Session', 'duration' => 30 ),
        'classic' => array( 'name' => 'Classic Session', 'duration' => 60 ),
        'wedding' => array( 'name' => 'Wedding Collection', 'duration' => 240 ),
    ),
    'tweller_flow_2_session_types' => array( 'portrait' => array( 'name' => 'Portrait' ) ),
);

function get_option( $k, $d = false ) { global $OPTIONS; return $OPTIONS[$k] ?? $d; }
function update_option( $k, $v, $a = null ) { global $OPTIONS; $OPTIONS[$k] = $v; return true; }
function delete_option( $k ) { global $OPTIONS; unset($OPTIONS[$k]); return true; }
function apply_filters( $tag, $value ) { return $value; }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function get_temp_dir() { return sys_get_temp_dir() . '/'; }
function esc_html( $s ) { return htmlspecialchars( (string) $s ); }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function current_time( $t ) { return $t === 'timestamp' ? time() : gmdate( 'Y-m-d H:i:s' ); }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function number_format_i18n( $n ) { return number_format( $n ); }

require_once ( $argv[2] ?? ( dirname(__DIR__) . '/tweller-bookings-wp/includes/class-session.php' ) );

// Only the two builders are under test; stub out what they reach for.
class TwellerFlow2_Notifications_Stub {
    const CALENDAR_EMAIL = 'studio@twellerstudios.com';
    public static function get_tracker_url( $c ) { return 'https://example.test/track/?code=' . $c; }
}

// Pull build_ics out of the real file so we test the shipped code, not a copy.
$src = file_get_contents( $argv[1] ?? ( dirname(__DIR__) . '/tweller-bookings-wp/includes/class-notifications.php' ) );
$start = strpos( $src, '    public static function build_ics(' );
$end   = strrpos( $src, '}' ); // final class brace
$method = substr( $src, $start, $end - $start );
$method = str_replace( 'self::get_tracker_url', 'TwellerFlow2_Notifications_Stub::get_tracker_url', $method );
$method = str_replace( 'self::CALENDAR_EMAIL', 'TwellerFlow2_Notifications_Stub::CALENDAR_EMAIL', $method );
eval( 'class IcsHarness { ' . $method . ' }' );

$tz  = new DateTimeZone( 'America/Port_of_Spain' );
$fail = 0;

$cases = array(
    array( 'mini',    '2026-09-05', '14:00:00', '2:00 PM',  30 ),
    array( 'classic', '2026-09-05', '09:30:00', '9:30 AM',  60 ),
    array( 'wedding', '2026-12-24', '16:00:00', '4:00 PM',  240 ),
    array( 'classic', '2026-03-08', '23:30:00', '11:30 PM', 60 ),  // crosses midnight UTC
    array( 'mini',    '2026-01-01', '00:15:00', '12:15 AM', 30 ),  // crosses back a day
);

echo "booked (Trinidad)        invite shows            length   verdict\n";
echo str_repeat( '-', 74 ) . "\n";

foreach ( $cases as list( $pkg, $date, $time, $expect_label, $expect_mins ) ) {
    $session = (object) array(
        'session_date' => $date, 'session_time' => $time,
        'package_type' => $pkg, 'session_type' => 'portrait',
        'client_name' => 'Marsha Alleyne', 'client_email' => 'm@example.com',
        'client_phone' => '1868-555-0100', 'location' => 'Maracas Bay',
        'tracking_code' => 'TEST-' . $pkg, 'total_amount' => 1200.00,
    );

    $path = IcsHarness::build_ics( $session, 'CONFIRMED', 1 );
    $ics  = file_get_contents( $path );
    @unlink( $path );

    preg_match( '/DTSTART:(\d{8}T\d{6}Z)/', $ics, $ms );
    preg_match( '/DTEND:(\d{8}T\d{6}Z)/', $ics, $me );

    $s = DateTime::createFromFormat( 'Ymd\THis\Z', $ms[1], new DateTimeZone('UTC') );
    $e = DateTime::createFromFormat( 'Ymd\THis\Z', $me[1], new DateTimeZone('UTC') );
    $s->setTimezone( $tz ); $e->setTimezone( $tz );

    $shown = $s->format( 'g:i A' );
    $mins  = ( $e->getTimestamp() - $s->getTimestamp() ) / 60;
    $dateOK = $s->format( 'Y-m-d' ) === $date;
    $ok = ( $shown === $expect_label ) && ( $mins == $expect_mins ) && $dateOK;
    if ( ! $ok ) $fail++;

    printf( "%s %-8s   %s %-8s  %4d min   %s\n",
        $date, $time, $s->format('Y-m-d'), $shown, $mins,
        $ok ? 'PASS' : "FAIL (want $expect_label / $expect_mins min on $date)" );
}

// ── Google Calendar event payload ──────────────────────────────────────────
// Same booking, different channel: the event must open at the booked local
// wall-clock and run for the package's own duration, not a keyword guess.
function wp_json_encode( $d ) { return json_encode( $d ); }
class TwellerFlow2_Google_Contacts {}
require_once dirname(__DIR__) . '/tweller-bookings-wp/includes/class-google-calendar.php';

$m = new ReflectionMethod( 'TwellerFlow2_Google_Calendar', 'build_event' );
$m->setAccessible( true );

echo "\nGoogle Calendar event\n";
echo str_repeat( '-', 74 ) . "\n";
echo "booked                   start                 end                   len  verdict\n";

foreach ( $cases as list( $pkg, $date, $time, $expect_label, $expect_mins ) ) {
    $session = (object) array(
        'id' => 1, 'session_date' => $date, 'session_time' => $time,
        'package_type' => $pkg, 'session_type' => 'portrait',
        'client_name' => 'Marsha Alleyne', 'client_phone' => '1868-555-0100',
        'location' => 'Maracas Bay', 'tracking_code' => 'TEST',
        'payment_status' => 'paid',
    );
    $ev = $m->invoke( null, $session );

    $s = new DateTime( $ev['start']['dateTime'], $tz );
    $e = new DateTime( $ev['end']['dateTime'], $tz );
    $mins = ( $e->getTimestamp() - $s->getTimestamp() ) / 60;

    $ok = $s->format( 'Y-m-d' ) === $date
        && $s->format( 'g:i A' ) === $expect_label
        && $mins == $expect_mins
        && $ev['start']['timeZone'] === 'America/Port_of_Spain';
    if ( ! $ok ) $fail++;

    printf( "%s %-8s   %s  %s  %4d %s\n", $date, $time,
        $ev['start']['dateTime'], $ev['end']['dateTime'], $mins,
        $ok ? ' PASS' : " FAIL (want $expect_label / $expect_mins min)" );
}

echo "\n" . ( $fail ? "$fail FAILURES\n" : "All invites and calendar events match the booked local time.\n" );
exit( $fail ? 1 : 0 );
