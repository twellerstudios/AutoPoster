<?php
/**
 * Self-contained QR code generator (no external service, no Composer library).
 *
 * A faithful PHP port of Project Nayuki's public-domain QR Code generator,
 * trimmed to what the engine needs: byte-mode encoding, ECC level M (with an
 * automatic bump to L for longer URLs), automatic version selection (1–10 —
 * far more than a `/event/{slug}/upload/` URL will ever need) and automatic
 * mask selection with the standard penalty scoring.
 *
 * Outputs a crisp SVG (preferred — infinitely scalable, tiny) and a PNG when
 * GD is available. Everything a print/flyer/on-screen QR needs.
 *
 * Reference: https://www.nayuki.io/page/qr-code-generator-library (MIT/public
 * domain). Ported and adapted for WordPress by Tweller Studios.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class TEPE_QR {

    // Error-correction levels → format bits.
    const ECC_L = 1; // ~7%  recovery
    const ECC_M = 0; // ~15% recovery

    // ECC codewords per block, columns = versions 1..10.
    private static $ECC_CW_PER_BLOCK = array(
        self::ECC_L => array( 7, 10, 15, 20, 26, 18, 20, 24, 30, 18 ),
        self::ECC_M => array( 10, 16, 26, 18, 24, 16, 18, 22, 22, 26 ),
    );
    // Number of error-correction blocks, columns = versions 1..10.
    private static $NUM_EC_BLOCKS = array(
        self::ECC_L => array( 1, 1, 1, 1, 1, 2, 2, 2, 2, 2 ),
        self::ECC_M => array( 1, 1, 1, 2, 2, 4, 4, 4, 5, 5 ),
    );

    const PENALTY_N1 = 3;
    const PENALTY_N2 = 3;
    const PENALTY_N3 = 40;
    const PENALTY_N4 = 10;

    /**
     * Build the module matrix (2D array of bool: true = dark) for $text.
     * Tries ECC M first, falls back to L if the text is too long for v10@M.
     * Returns array on success or false if it genuinely doesn't fit.
     */
    public static function matrix( $text ) {
        $bytes = array_values( unpack( 'C*', $text ) );
        foreach ( array( self::ECC_M, self::ECC_L ) as $ecl ) {
            $m = self::encode( $bytes, $ecl );
            if ( $m !== false ) return $m;
        }
        return false;
    }

    private static function encode( $data, $ecl ) {
        $len = count( $data );

        // Pick the smallest version (1..10) whose data capacity fits.
        $version = 0;
        for ( $v = 1; $v <= 10; $v++ ) {
            $cap_bits = self::num_data_codewords( $v, $ecl ) * 8;
            $cc_bits  = ( $v <= 9 ) ? 8 : 16;      // byte-mode char count
            $used     = 4 + $cc_bits + 8 * $len;   // mode + count + payload
            if ( $used <= $cap_bits ) { $version = $v; break; }
        }
        if ( $version === 0 ) return false;

        $cc_bits = ( $version <= 9 ) ? 8 : 16;

        // ── Bit stream ──
        $bits = array();
        self::append_bits( $bits, 0x4, 4 );        // byte mode
        self::append_bits( $bits, $len, $cc_bits ); // char count
        foreach ( $data as $b ) self::append_bits( $bits, $b, 8 );

        $capacity = self::num_data_codewords( $version, $ecl ) * 8;
        // Terminator + byte padding + pad codewords.
        $term = min( 4, $capacity - count( $bits ) );
        self::append_bits( $bits, 0, $term );
        while ( count( $bits ) % 8 !== 0 ) $bits[] = 0;
        for ( $pad = 0xEC; count( $bits ) < $capacity; $pad ^= 0xEC ^ 0x11 ) {
            self::append_bits( $bits, $pad, 8 );
        }

        // Pack into data codewords.
        $data_cw = array();
        for ( $i = 0; $i < count( $bits ); $i += 8 ) {
            $byte = 0;
            for ( $j = 0; $j < 8; $j++ ) $byte = ( $byte << 1 ) | $bits[ $i + $j ];
            $data_cw[] = $byte;
        }

        $all_cw = self::add_ecc_interleave( $data_cw, $version, $ecl );

        // ── Draw ──
        $size = $version * 4 + 17;
        $modules  = array();
        $isfunc   = array();
        for ( $y = 0; $y < $size; $y++ ) {
            $modules[ $y ] = array_fill( 0, $size, false );
            $isfunc[ $y ]  = array_fill( 0, $size, false );
        }

        self::draw_function_patterns( $modules, $isfunc, $size, $version, $ecl );
        self::draw_codewords( $modules, $isfunc, $size, $all_cw );

        // Choose the mask with the lowest penalty.
        $best_mask = 0;
        $min_pen   = PHP_INT_MAX;
        for ( $mask = 0; $mask < 8; $mask++ ) {
            self::apply_mask( $modules, $isfunc, $size, $mask );
            self::draw_format_bits( $modules, $isfunc, $size, $ecl, $mask );
            $pen = self::penalty( $modules, $size );
            if ( $pen < $min_pen ) { $min_pen = $pen; $best_mask = $mask; }
            self::apply_mask( $modules, $isfunc, $size, $mask ); // undo (XOR is its own inverse)
        }
        self::apply_mask( $modules, $isfunc, $size, $best_mask );
        self::draw_format_bits( $modules, $isfunc, $size, $ecl, $best_mask );

        return $modules;
    }

    // ── Bit helpers ────────────────────────────────────────────────────────

    private static function append_bits( &$bits, $val, $n ) {
        for ( $i = $n - 1; $i >= 0; $i-- ) $bits[] = ( $val >> $i ) & 1;
    }
    private static function get_bit( $x, $i ) { return ( $x >> $i ) & 1; }

    // ── Capacity maths ─────────────────────────────────────────────────────

    private static function num_raw_data_modules( $ver ) {
        $result = ( 16 * $ver + 128 ) * $ver + 64;
        if ( $ver >= 2 ) {
            $num_align = intdiv( $ver, 7 ) + 2;
            $result -= ( 25 * $num_align - 10 ) * $num_align - 55;
            if ( $ver >= 7 ) $result -= 36;
        }
        return $result;
    }

    private static function num_data_codewords( $ver, $ecl ) {
        $raw    = intdiv( self::num_raw_data_modules( $ver ), 8 );
        $blocks = self::$NUM_EC_BLOCKS[ $ecl ][ $ver - 1 ];
        $ecpb   = self::$ECC_CW_PER_BLOCK[ $ecl ][ $ver - 1 ];
        return $raw - $ecpb * $blocks;
    }

    // ── Reed–Solomon (GF(256), primitive 0x11D) ────────────────────────────

    private static function gf_mul( $x, $y ) {
        $z = 0;
        for ( $i = 7; $i >= 0; $i-- ) {
            $z = ( $z << 1 ) ^ ( ( ( $z >> 7 ) & 1 ) * 0x11D );
            $z ^= ( ( $y >> $i ) & 1 ) * $x;
        }
        return $z & 0xFF;
    }

    private static function rs_divisor( $degree ) {
        $result = array_fill( 0, $degree, 0 );
        $result[ $degree - 1 ] = 1;
        $root = 1;
        for ( $i = 0; $i < $degree; $i++ ) {
            for ( $j = 0; $j < $degree; $j++ ) {
                $result[ $j ] = self::gf_mul( $result[ $j ], $root );
                if ( $j + 1 < $degree ) $result[ $j ] ^= $result[ $j + 1 ];
            }
            $root = self::gf_mul( $root, 0x02 );
        }
        return $result;
    }

    private static function rs_remainder( $data, $divisor ) {
        $degree = count( $divisor );
        $result = array_fill( 0, $degree, 0 );
        foreach ( $data as $b ) {
            $factor = $b ^ array_shift( $result );
            $result[] = 0;
            for ( $i = 0; $i < $degree; $i++ ) {
                $result[ $i ] ^= self::gf_mul( $divisor[ $i ], $factor );
            }
        }
        return $result;
    }

    private static function add_ecc_interleave( $data, $ver, $ecl ) {
        $num_blocks = self::$NUM_EC_BLOCKS[ $ecl ][ $ver - 1 ];
        $ec_len     = self::$ECC_CW_PER_BLOCK[ $ecl ][ $ver - 1 ];
        $raw_cw     = intdiv( self::num_raw_data_modules( $ver ), 8 );
        $num_short  = $num_blocks - ( $raw_cw % $num_blocks );
        $short_len  = intdiv( $raw_cw, $num_blocks );
        $rs_div     = self::rs_divisor( $ec_len );

        $blocks = array();
        $k = 0;
        for ( $i = 0; $i < $num_blocks; $i++ ) {
            $dat_len = $short_len - $ec_len + ( $i < $num_short ? 0 : 1 );
            $dat = array_slice( $data, $k, $dat_len );
            $k  += $dat_len;
            $ecc = self::rs_remainder( $dat, $rs_div );
            if ( $i < $num_short ) $dat[] = 0; // placeholder for column alignment
            $blocks[] = array_merge( $dat, $ecc );
        }

        $result = array();
        $block_total_len = count( $blocks[0] );
        for ( $i = 0; $i < $block_total_len; $i++ ) {
            for ( $j = 0; $j < $num_blocks; $j++ ) {
                // Skip the placeholder byte in short blocks.
                if ( $i !== $short_len - $ec_len || $j >= $num_short ) {
                    $result[] = $blocks[ $j ][ $i ];
                }
            }
        }
        return $result;
    }

    // ── Module placement ───────────────────────────────────────────────────

    private static function set_fn( &$modules, &$isfunc, $size, $x, $y, $dark ) {
        if ( $x < 0 || $y < 0 || $x >= $size || $y >= $size ) return;
        $modules[ $y ][ $x ] = (bool) $dark;
        $isfunc[ $y ][ $x ]  = true;
    }

    private static function draw_function_patterns( &$modules, &$isfunc, $size, $ver, $ecl ) {
        // Timing patterns
        for ( $i = 0; $i < $size; $i++ ) {
            self::set_fn( $modules, $isfunc, $size, 6, $i, $i % 2 === 0 );
            self::set_fn( $modules, $isfunc, $size, $i, 6, $i % 2 === 0 );
        }
        // Finder patterns (3 corners) + separators
        self::draw_finder( $modules, $isfunc, $size, 3, 3 );
        self::draw_finder( $modules, $isfunc, $size, $size - 4, 3 );
        self::draw_finder( $modules, $isfunc, $size, 3, $size - 4 );

        // Alignment patterns
        $pos = self::align_positions( $ver, $size );
        $n = count( $pos );
        for ( $i = 0; $i < $n; $i++ ) {
            for ( $j = 0; $j < $n; $j++ ) {
                // Skip the three finder-pattern corners.
                if ( ( $i === 0 && $j === 0 ) || ( $i === 0 && $j === $n - 1 ) || ( $i === $n - 1 && $j === 0 ) ) continue;
                self::draw_align( $modules, $isfunc, $size, $pos[ $i ], $pos[ $j ] );
            }
        }

        // Reserve format & version areas (values filled later).
        self::draw_format_bits( $modules, $isfunc, $size, $ecl, 0 );
        self::draw_version( $modules, $isfunc, $size, $ver );
    }

    private static function draw_finder( &$modules, &$isfunc, $size, $cx, $cy ) {
        for ( $dy = -4; $dy <= 4; $dy++ ) {
            for ( $dx = -4; $dx <= 4; $dx++ ) {
                $dist = max( abs( $dx ), abs( $dy ) );
                self::set_fn( $modules, $isfunc, $size, $cx + $dx, $cy + $dy, ( $dist !== 2 && $dist !== 4 ) );
            }
        }
    }

    private static function draw_align( &$modules, &$isfunc, $size, $cx, $cy ) {
        for ( $dy = -2; $dy <= 2; $dy++ ) {
            for ( $dx = -2; $dx <= 2; $dx++ ) {
                self::set_fn( $modules, $isfunc, $size, $cx + $dx, $cy + $dy, max( abs( $dx ), abs( $dy ) ) !== 1 );
            }
        }
    }

    private static function align_positions( $ver, $size ) {
        if ( $ver === 1 ) return array();
        $num_align = intdiv( $ver, 7 ) + 2;
        $step = (int) ( ceil( ( $ver * 4 + 4 ) / ( $num_align * 2 - 2 ) ) * 2 );
        $result = array( 6 );
        for ( $p = $size - 7; count( $result ) < $num_align; $p -= $step ) {
            array_splice( $result, 1, 0, $p );
        }
        return $result;
    }

    private static function draw_format_bits( &$modules, &$isfunc, $size, $ecl, $mask ) {
        $data = ( $ecl << 3 ) | $mask;
        $rem = $data;
        for ( $i = 0; $i < 10; $i++ ) $rem = ( $rem << 1 ) ^ ( ( ( $rem >> 9 ) & 1 ) * 0x537 );
        $bits = ( ( $data << 10 ) | $rem ) ^ 0x5412;

        // First copy (around top-left finder)
        for ( $i = 0; $i <= 5; $i++ ) self::set_fn( $modules, $isfunc, $size, 8, $i, self::get_bit( $bits, $i ) );
        self::set_fn( $modules, $isfunc, $size, 8, 7, self::get_bit( $bits, 6 ) );
        self::set_fn( $modules, $isfunc, $size, 8, 8, self::get_bit( $bits, 7 ) );
        self::set_fn( $modules, $isfunc, $size, 7, 8, self::get_bit( $bits, 8 ) );
        for ( $i = 9; $i < 15; $i++ ) self::set_fn( $modules, $isfunc, $size, 14 - $i, 8, self::get_bit( $bits, $i ) );

        // Second copy (split across the other two finders)
        for ( $i = 0; $i < 8; $i++ ) self::set_fn( $modules, $isfunc, $size, $size - 1 - $i, 8, self::get_bit( $bits, $i ) );
        for ( $i = 8; $i < 15; $i++ ) self::set_fn( $modules, $isfunc, $size, 8, $size - 15 + $i, self::get_bit( $bits, $i ) );
        self::set_fn( $modules, $isfunc, $size, 8, $size - 8, true ); // always-dark module
    }

    private static function draw_version( &$modules, &$isfunc, $size, $ver ) {
        if ( $ver < 7 ) return;
        $rem = $ver;
        for ( $i = 0; $i < 12; $i++ ) $rem = ( $rem << 1 ) ^ ( ( ( $rem >> 11 ) & 1 ) * 0x1F25 );
        $bits = ( $ver << 12 ) | $rem;
        for ( $i = 0; $i < 18; $i++ ) {
            $bit = self::get_bit( $bits, $i );
            $a = $size - 11 + $i % 3;
            $b = intdiv( $i, 3 );
            self::set_fn( $modules, $isfunc, $size, $a, $b, $bit );
            self::set_fn( $modules, $isfunc, $size, $b, $a, $bit );
        }
    }

    private static function draw_codewords( &$modules, &$isfunc, $size, $cw ) {
        $bit_len = count( $cw ) * 8;
        $i = 0; // bit index
        for ( $right = $size - 1; $right >= 1; $right -= 2 ) {
            if ( $right === 6 ) $right = 5; // skip vertical timing column
            for ( $vert = 0; $vert < $size; $vert++ ) {
                for ( $j = 0; $j < 2; $j++ ) {
                    $x = $right - $j;
                    $upward = ( ( ( $right + 1 ) & 2 ) === 0 );
                    $y = $upward ? ( $size - 1 - $vert ) : $vert;
                    if ( ! $isfunc[ $y ][ $x ] && $i < $bit_len ) {
                        $modules[ $y ][ $x ] = ( self::get_bit( $cw[ $i >> 3 ], 7 - ( $i & 7 ) ) !== 0 );
                        $i++;
                    }
                }
            }
        }
    }

    private static function apply_mask( &$modules, &$isfunc, $size, $mask ) {
        for ( $y = 0; $y < $size; $y++ ) {
            for ( $x = 0; $x < $size; $x++ ) {
                if ( $isfunc[ $y ][ $x ] ) continue;
                switch ( $mask ) {
                    case 0: $invert = ( ( $x + $y ) % 2 === 0 ); break;
                    case 1: $invert = ( $y % 2 === 0 ); break;
                    case 2: $invert = ( $x % 3 === 0 ); break;
                    case 3: $invert = ( ( $x + $y ) % 3 === 0 ); break;
                    case 4: $invert = ( ( intdiv( $x, 3 ) + intdiv( $y, 2 ) ) % 2 === 0 ); break;
                    case 5: $invert = ( ( ( $x * $y ) % 2 ) + ( ( $x * $y ) % 3 ) === 0 ); break;
                    case 6: $invert = ( ( ( ( $x * $y ) % 2 ) + ( ( $x * $y ) % 3 ) ) % 2 === 0 ); break;
                    default: $invert = ( ( ( ( $x + $y ) % 2 ) + ( ( $x * $y ) % 3 ) ) % 2 === 0 ); break;
                }
                if ( $invert ) $modules[ $y ][ $x ] = ! $modules[ $y ][ $x ];
            }
        }
    }

    // ── Penalty scoring (for mask selection) ────────────────────────────────

    private static function penalty( $modules, $size ) {
        $result = 0;

        // Rules 1 & 3 — rows.
        for ( $y = 0; $y < $size; $y++ ) {
            $run_color = false; $run_x = 0; $hist = array( 0,0,0,0,0,0,0 );
            for ( $x = 0; $x < $size; $x++ ) {
                if ( $modules[ $y ][ $x ] === $run_color ) {
                    $run_x++;
                    if ( $run_x === 5 ) $result += self::PENALTY_N1;
                    elseif ( $run_x > 5 ) $result++;
                } else {
                    self::finder_add_history( $run_x, $hist, $size );
                    if ( ! $run_color ) $result += self::finder_count( $hist ) * self::PENALTY_N3;
                    $run_color = $modules[ $y ][ $x ]; $run_x = 1;
                }
            }
            $result += self::finder_terminate( $run_color, $run_x, $hist, $size ) * self::PENALTY_N3;
        }
        // Rules 1 & 3 — columns.
        for ( $x = 0; $x < $size; $x++ ) {
            $run_color = false; $run_y = 0; $hist = array( 0,0,0,0,0,0,0 );
            for ( $y = 0; $y < $size; $y++ ) {
                if ( $modules[ $y ][ $x ] === $run_color ) {
                    $run_y++;
                    if ( $run_y === 5 ) $result += self::PENALTY_N1;
                    elseif ( $run_y > 5 ) $result++;
                } else {
                    self::finder_add_history( $run_y, $hist, $size );
                    if ( ! $run_color ) $result += self::finder_count( $hist ) * self::PENALTY_N3;
                    $run_color = $modules[ $y ][ $x ]; $run_y = 1;
                }
            }
            $result += self::finder_terminate( $run_color, $run_y, $hist, $size ) * self::PENALTY_N3;
        }

        // Rule 2 — 2x2 blocks of one colour.
        for ( $y = 0; $y < $size - 1; $y++ ) {
            for ( $x = 0; $x < $size - 1; $x++ ) {
                $c = $modules[ $y ][ $x ];
                if ( $c === $modules[ $y ][ $x + 1 ] && $c === $modules[ $y + 1 ][ $x ] && $c === $modules[ $y + 1 ][ $x + 1 ] ) {
                    $result += self::PENALTY_N2;
                }
            }
        }

        // Rule 4 — dark/light balance.
        $dark = 0;
        for ( $y = 0; $y < $size; $y++ ) foreach ( $modules[ $y ] as $m ) if ( $m ) $dark++;
        $total = $size * $size;
        $k = (int) ( ceil( abs( $dark * 20 - $total * 10 ) / $total ) - 1 );
        $result += $k * self::PENALTY_N4;

        return $result;
    }

    private static function finder_add_history( $run, &$hist, $size ) {
        if ( $hist[0] === 0 ) $run += $size; // light border allowance
        array_pop( $hist );
        array_unshift( $hist, $run );
    }

    private static function finder_count( $hist ) {
        $n = $hist[1];
        $core = ( $n > 0 && $hist[2] === $n && $hist[3] === $n * 3 && $hist[4] === $n && $hist[5] === $n );
        return ( $core && $hist[0] >= $n * 4 && $hist[6] >= $n ? 1 : 0 )
             + ( $core && $hist[6] >= $n * 4 && $hist[0] >= $n ? 1 : 0 );
    }

    private static function finder_terminate( $run_color, $run, $hist, $size ) {
        if ( $run_color ) { self::finder_add_history( $run, $hist, $size ); $run = 0; }
        $run += $size;
        self::finder_add_history( $run, $hist, $size );
        return self::finder_count( $hist );
    }

    // ── Renderers ──────────────────────────────────────────────────────────

    /**
     * SVG markup for $text. $module_px is the CSS pixel size per QR module;
     * $border is the quiet-zone width in modules (min 4 per spec).
     */
    public static function svg( $text, $module_px = 6, $border = 4, $dark = '#101010', $light = '#ffffff' ) {
        $m = self::matrix( $text );
        if ( $m === false ) return '';
        $size  = count( $m );
        $dim   = ( $size + $border * 2 ) * $module_px;

        $path = '';
        for ( $y = 0; $y < $size; $y++ ) {
            for ( $x = 0; $x < $size; $x++ ) {
                if ( $m[ $y ][ $x ] ) {
                    $px = ( $x + $border ) * $module_px;
                    $py = ( $y + $border ) * $module_px;
                    $path .= "M{$px},{$py}h{$module_px}v{$module_px}h-{$module_px}z";
                }
            }
        }

        $svg  = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $dim . ' ' . $dim . '" ';
        $svg .= 'width="' . $dim . '" height="' . $dim . '" shape-rendering="crispEdges" role="img" aria-label="QR code">';
        $svg .= '<rect width="' . $dim . '" height="' . $dim . '" fill="' . esc_attr( $light ) . '"/>';
        $svg .= '<path d="' . $path . '" fill="' . esc_attr( $dark ) . '"/>';
        $svg .= '</svg>';
        return $svg;
    }

    /**
     * PNG binary for $text using GD. Returns raw PNG bytes or false if GD is
     * unavailable. Callers that only need display should prefer svg().
     */
    public static function png( $text, $module_px = 8, $border = 4 ) {
        if ( ! function_exists( 'imagecreatetruecolor' ) ) return false;
        $m = self::matrix( $text );
        if ( $m === false ) return false;
        $size = count( $m );
        $dim  = ( $size + $border * 2 ) * $module_px;

        $img   = imagecreatetruecolor( $dim, $dim );
        $white = imagecolorallocate( $img, 255, 255, 255 );
        $black = imagecolorallocate( $img, 16, 16, 16 );
        imagefilledrectangle( $img, 0, 0, $dim, $dim, $white );

        for ( $y = 0; $y < $size; $y++ ) {
            for ( $x = 0; $x < $size; $x++ ) {
                if ( $m[ $y ][ $x ] ) {
                    $px = ( $x + $border ) * $module_px;
                    $py = ( $y + $border ) * $module_px;
                    imagefilledrectangle( $img, $px, $py, $px + $module_px - 1, $py + $module_px - 1, $black );
                }
            }
        }

        ob_start();
        imagepng( $img );
        $data = ob_get_clean();
        imagedestroy( $img );
        return $data;
    }

    /** A ready-to-embed data: URI for the SVG (handy in emails / admin). */
    public static function svg_data_uri( $text, $module_px = 6 ) {
        $svg = self::svg( $text, $module_px );
        if ( $svg === '' ) return '';
        return 'data:image/svg+xml;base64,' . base64_encode( $svg );
    }
}
