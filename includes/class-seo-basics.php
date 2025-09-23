<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Nfinite SEO Basics Scanner
 *
 * Free-tier checks:
 * - Title tag: existence + length band
 * - Meta description: existence + length band
 * - H1: existence + single-H1 rule
 *
 * Scoring:
 *   Title (34%), Meta (33%), H1 (33%)  → 0..100
 *
 * Usage:
 *   $result = Nfinite_SEO_Basics::analyze( $html, $url );
 *   // or, if you want a section payload directly:
 *   $section = Nfinite_SEO_Basics::as_section( $html, $url );
 */
class Nfinite_SEO_Basics {

	// Ideal ranges (inclusive) based on common SEO guidance
	const TITLE_IDEAL_MIN = 50;
	const TITLE_IDEAL_MAX = 60;
	const TITLE_SOFT_MIN  = 35;   // warn under
	const TITLE_SOFT_MAX  = 65;   // warn over

	const META_IDEAL_MIN  = 120;
	const META_IDEAL_MAX  = 160;
	const META_SOFT_MIN   = 80;   // warn under
	const META_SOFT_MAX   = 180;  // warn over

	/**
	 * Run all basic checks against provided HTML
	 *
	 * @param string $html
	 * @param string $url  (for context in messages)
	 * @return array {
	 *   score: int 0..100,
	 *   grade: string A..F,
	 *   checks: {
	 *     title: { exists, text, length, score, issues[] },
	 *     meta_description: { exists, text, length, score, issues[] },
	 *     h1: { count, texts[], score, issues[] }
	 *   },
	 *   messages: string[]
	 * }
	 */
	public static function analyze( $html, $url = '' ) {
		if ( ! is_string( $html ) || $html === '' ) {
			return self::empty_result( 'Empty HTML received; unable to run SEO basics.', $url );
		}

		$title = self::check_title( $html );
		$meta  = self::check_meta_description( $html );
		$h1    = self::check_h1( $html );

		// Weighted score
		$score = (int) round(
			($title['score'] * 0.34) +
			($meta['score']  * 0.33) +
			($h1['score']    * 0.33)
		);

		return array(
			'score'    => max(0, min(100, $score)),
			'grade'    => self::score_to_grade( $score ),
			'checks'   => array(
				'title'            => $title,
				'meta_description' => $meta,
				'h1'               => $h1,
			),
			'messages' => self::collect_messages( $title, $meta, $h1, $url ),
		);
	}

	/**
	 * Convenience: wrap analyze() result in the "section" shape your admin UI uses.
	 * Returns:
	 * [
	 *   'label'   => 'SEO Basics',
	 *   'score'   => (int),
	 *   'grade'   => 'A'..'F',
	 *   'details' => <analyze() payload>
	 * ]
	 */
	public static function as_section( $html, $url = '' ) {
		$scan = self::analyze( $html, $url );
		return array(
			'label'   => 'SEO Basics',
			'score'   => (int) $scan['score'],
			'grade'   => (string) $scan['grade'],
			'details' => $scan, // keeps checks at details['checks'] as your UI expects
		);
	}

	// ----------------------------------------------------------
	// Individual checks
	// ----------------------------------------------------------

	public static function check_title( $html ) {
		$issues = array();
		$text   = self::extract_first_tag_text( $html, 'title' );
		$exists = ( $text !== '' );

		if ( ! $exists ) {
			$issues[] = 'Missing <title> tag.';
			return array(
				'exists'  => false,
				'text'    => '',
				'length'  => 0,
				'score'   => 0,
				'issues'  => $issues,
			);
		}

		$text = self::normalize_ws( $text );
		$len  = self::mb_len( $text );

		$score = 100;

		// Soft warnings and deductions
		if ( $len < self::TITLE_SOFT_MIN ) {
			$issues[] = sprintf( 'Title is very short (%d chars). Consider adding context/key terms.', $len );
			$score -= 25;
		} elseif ( $len > self::TITLE_SOFT_MAX ) {
			$issues[] = sprintf( 'Title is long (%d chars). It may be truncated in SERPs.', $len );
			$score -= 15;
		}

		// Ideal band nudge (no penalty if outside; we already handled soft)
		if ( $len >= self::TITLE_IDEAL_MIN && $len <= self::TITLE_IDEAL_MAX ) {
			$score = min( 100, $score + 5 );
		}

		return array(
			'exists' => true,
			'text'   => $text,
			'length' => $len,
			'score'  => max( 0, min( 100, (int) $score ) ),
			'issues' => $issues,
		);
	}

	public static function check_meta_description( $html ) {
		$issues = array();
		$text   = self::extract_meta_name_content( $html, 'description' );
		$exists = ( $text !== '' );

		if ( ! $exists ) {
			$issues[] = 'Missing meta description.';
			return array(
				'exists' => false,
				'text'   => '',
				'length' => 0,
				'score'  => 0,
				'issues' => $issues,
			);
		}

		$text = self::normalize_ws( $text );
		$len  = self::mb_len( $text );

		$score = 100;

		if ( $len < self::META_SOFT_MIN ) {
			$issues[] = sprintf( 'Meta description is very short (%d chars). Add more detail/keywords.', $len );
			$score -= 25;
		} elseif ( $len > self::META_SOFT_MAX ) {
			$issues[] = sprintf( 'Meta description is long (%d chars). It may be truncated.', $len );
			$score -= 15;
		}

		if ( $len >= self::META_IDEAL_MIN && $len <= self::META_IDEAL_MAX ) {
			$score = min( 100, $score + 5 );
		}

		return array(
			'exists' => true,
			'text'   => $text,
			'length' => $len,
			'score'  => max( 0, min( 100, (int) $score ) ),
			'issues' => $issues,
		);
	}

	public static function check_h1( $html ) {
		$issues  = array();
		$matches = array();

		// Match all <h1>...</h1> (non-greedy, dotall)
		if ( preg_match_all( '#<h1\b[^>]*>(.*?)</h1>#is', $html, $matches ) ) {
			$texts = array_map( function( $t ) {
				$txt = self::wp_strip_all_tags_safe( $t );
				return self::normalize_ws( $txt );
			}, $matches[1] );
		} else {
			$texts = array();
		}

		$count = count( $texts );
		$score = 100;

		if ( $count === 0 ) {
			$issues[] = 'No <h1> found. Add a single descriptive H1 heading.';
			$score = 0;
		} elseif ( $count > 1 ) {
			$issues[] = sprintf( 'Found %d <h1> tags. Use a single H1 for clarity.', $count );
			$score -= min( 50, 10 * ( $count - 1 ) ); // gentle decay
		}

		return array(
			'count'  => $count,
			'texts'  => $texts,
			'score'  => max( 0, min( 100, (int) $score ) ),
			'issues' => $issues,
		);
	}

	// ----------------------------------------------------------
	// Helpers
	// ----------------------------------------------------------

	private static function extract_first_tag_text( $html, $tag ) {
		if ( ! is_string( $html ) || $html === '' ) return '';
		$tag = preg_quote( $tag, '#' );
		if ( preg_match( "#<{$tag}\b[^>]*>(.*?)</{$tag}>#is", $html, $m ) ) {
			return self::normalize_ws( self::wp_strip_all_tags_safe( $m[1] ) );
		}
		return '';
	}

	private static function extract_meta_name_content( $html, $name ) {
		if ( ! is_string( $html ) || $html === '' ) return '';
		$name = preg_quote( $name, '#' );
		// Support both orders of attributes: name then content, or content then name
		if ( preg_match( '#<meta\b[^>]*name=["\']'.$name.'["\'][^>]*content=["\'](.*?)["\'][^>]*>#is', $html, $m ) ) {
			return self::normalize_ws( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}
		if ( preg_match( '#<meta\b[^>]*content=["\'](.*?)["\'][^>]*name=["\']'.$name.'["\'][^>]*>#is', $html, $m2 ) ) {
			return self::normalize_ws( html_entity_decode( $m2[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}
		return '';
	}

	private static function normalize_ws( $s ) {
		return trim( preg_replace( '/\s+/u', ' ', (string) $s ) );
	}

	private static function empty_result( $msg, $url ) {
		$messages = array( $msg );
		if ( $url ) $messages[0] .= ' URL: ' . esc_url_raw( $url );

		return array(
			'score'    => 0,
			'grade'    => 'F',
			'checks'   => array(
				'title'            => array( 'exists' => false, 'text' => '', 'length' => 0, 'score' => 0, 'issues' => array() ),
				'meta_description' => array( 'exists' => false, 'text' => '', 'length' => 0, 'score' => 0, 'issues' => array() ),
				'h1'               => array( 'count' => 0, 'texts' => array(), 'score' => 0, 'issues' => array() ),
			),
			'messages' => $messages,
		);
	}

	private static function collect_messages( $title, $meta, $h1, $url ) {
		$messages = array();
		foreach ( array( $title, $meta, $h1 ) as $bucket ) {
			if ( ! empty( $bucket['issues'] ) ) {
				$messages = array_merge( $messages, $bucket['issues'] );
			}
		}
		// De-dup and clean
		$messages = array_values( array_unique( array_filter( $messages ) ) );

		// If nothing else and URL missing HTML, add a generic hint (caller often supplies this)
		if ( empty( $messages ) && $url ) {
			$messages[] = 'SEO basics scan completed for ' . esc_url_raw( $url ) . '.';
		}
		return $messages;
	}

	/**
	 * Convert score to letter grade
	 */
	public static function score_to_grade( $score ) {
		$s = (int) $score;
		if ( $s >= 90 ) return 'A';
		if ( $s >= 80 ) return 'B';
		if ( $s >= 70 ) return 'C';
		if ( $s >= 60 ) return 'D';
		return 'F';
	}

	/**
	 * Multibyte-safe strlen with graceful fallback
	 */
	private static function mb_len( $s ) {
		if ( function_exists('mb_strlen') ) {
			return (int) mb_strlen( $s, 'UTF-8' );
		}
		return (int) strlen( $s );
	}

	/**
	 * wp_strip_all_tags() wrapper that won’t fatally error outside admin
	 */
	private static function wp_strip_all_tags_safe( $text ) {
		if ( function_exists( 'wp_strip_all_tags' ) ) {
			return wp_strip_all_tags( $text );
		}
		return trim( strip_tags( $text ) );
	}
}
