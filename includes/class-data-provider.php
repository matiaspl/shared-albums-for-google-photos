<?php
/**
 * Data Provider: Fetches and extracts photo data from Google Photos
 *
 * Input Phase - Responsible for getting data from Google Photos
 *
 * @package YAGA_Shared_Albums
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Photos Provider Class
 */
class YAGA_Data_Provider {

	/**
	 * Full link format pattern (recommended)
	 *
	 * @var string
	 */
	private $full_link_pattern = '/^https:\/\/photos\.google\.com(?:\/u\/\d+)?\/share\//';

	/**
	 * Short link format pattern (deprecated)
	 *
	 * @var string
	 */
	private $short_link_pattern = '/^https:\/\/photos\.app\.goo\.gl\//';

	/**
	 * Combined validation pattern
	 *
	 * @var string
	 */
	private $validation_pattern = '/^https:\/\/photos\.google\.com(?:\/u\/\d+)?\/share\/|^https:\/\/photos\.app\.goo\.gl\//';

	/**
	 * Validate album URL format
	 *
	 * @param string $url Album URL
	 * @return array Validation result [valid, is_deprecated, error_message]
	 */
	public function validate_url( $url ) {
		if ( empty( $url ) ) {
			return array(
				'valid'         => false,
				'is_deprecated' => false,
				'error'         => __( 'URL is required', 'janzeman-shared-albums-for-google-photos' ),
			);
		}

		if ( ! preg_match( $this->validation_pattern, $url ) ) {
			return array(
				'valid'         => false,
				'is_deprecated' => false,
				'error'         => __( 'Invalid Google Photos share URL', 'janzeman-shared-albums-for-google-photos' ),
			);
		}

		$is_deprecated = preg_match( $this->short_link_pattern, $url );

		return array(
			'valid'         => true,
			'is_deprecated' => $is_deprecated,
			'error'         => null,
		);
	}

	/**
	 * Fetch album data from Google Photos
	 *
	 * @param string $url Album URL
	 * @return array Result [success, data, error]
	 */
	public function fetch_album( $url ) {
		// Validate before fetching
		$validation = $this->validate_url( $url );
		if ( ! $validation['valid'] ) {
			return array(
				'success' => false,
				'data'    => null,
				'error'   => $validation['error'],
			);
		}

		// Fetch HTML content
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 10,
				'user-agent' => $this->get_default_user_agent(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'data'    => null,
				'error'   => sprintf(
					/* translators: %s: error message returned from WordPress HTTP API */
					__( 'Failed to fetch album: %s', 'janzeman-shared-albums-for-google-photos' ),
					$response->get_error_message()
				),
			);
		}

		$html = wp_remote_retrieve_body( $response );

		if ( empty( $html ) ) {
			return array(
				'success' => false,
				'data'    => null,
				'error'   => __( 'Empty response from Google Photos', 'janzeman-shared-albums-for-google-photos' ),
			);
		}

		// Extract album metadata
		$title  = $this->extract_album_title( $html );
		$photos = $this->extract_photos( $html, $url );

		if ( empty( $photos ) ) {
			return array(
				'success' => false,
				'data'    => null,
				'error'   => __( 'No photos found in album', 'janzeman-shared-albums-for-google-photos' ),
			);
		}

		return array(
			'success'       => true,
			'is_deprecated' => $validation['is_deprecated'],
			'data'          => array(
				'title'  => $title,
				'photos' => $photos, // Array of ['url' => ..., 'filename' => ...]
				'count'  => count( $photos ),
			),
			'error'         => null,
		);
	}

	/**
	 * Extract album title from HTML
	 *
	 * Google Photos HTML contains the album title in the <title> tag, which is more reliable
	 * than OG tags which may contain photo-specific dates.
	 *
	 * @param string $html HTML content
	 * @return string|null Album title or null
	 */
	private function extract_album_title( $html ) {
		// Try to extract from <title> tag first (more reliable for album name)
		if ( preg_match( '/<title[^>]*>([^<]+)<\/title>/i', $html, $match ) ) {
			$title = html_entity_decode( $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			// <title> often has format: "Album Name - Google Photos"
			$title = preg_replace( '/\s*-\s*Google Photos\s*$/i', '', $title );
			$title = trim( $title );
			if ( ! empty( $title ) ) {
				return $this->clean_album_title( $title );
			}
		}

		// Fallback to og:title meta tag
		if ( preg_match( '/<\s*meta\s+property=["\']og:title["\']\s+content=["\']([^"\']+)["\']/i', $html, $match ) ) {
			$title = $match[1];
		} elseif ( preg_match( '/<\s*meta\s+content=["\']([^"\']+)["\']\s+property=["\']og:title["\']/i', $html, $match ) ) {
			$title = $match[1];
		} else {
			return null;
		}

		// Clean the title: remove dates, camera info, icons
		return $this->clean_album_title( $title );
	}

	/**
	 * Clean album title by removing dates, camera icons, and metadata
	 *
	 * Google Photos OG:title often contains photo-specific metadata like:
	 * "Saturday, Jan 29, 2005" or "Album Name - Jan 29, 2005"
	 * We need to extract just the album name portion.
	 *
	 * @param string $title Raw title from Open Graph tag
	 * @return string Cleaned title
	 */
	private function clean_album_title( $title ) {
		// Remove emoji characters (camera icons, etc.)
		$title = preg_replace( '/[\x{1F300}-\x{1F9FF}]/u', '', $title );

		// Remove full date patterns with day of week: "Saturday, Jan 29, 2005" or "Monday, January 15, 2024"
		$title = preg_replace( '/\b(?:Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday),?\s+(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\s+\d{1,2},?\s+\d{4}\b/i', '', $title );

		// Remove date patterns like "Jan 2024", "January 2024", "Jan 29, 2005"
		$title = preg_replace( '/\b(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\s+\d{1,2},?\s+\d{4}\b/i', '', $title );
		$title = preg_replace( '/\b(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\s+\d{4}\b/i', '', $title );

		// Remove ISO date format: "2024-01-15"
		$title = preg_replace( '/\b\d{4}-\d{2}-\d{2}\b/', '', $title );

		// Remove slash date format: "01/15/2024"
		$title = preg_replace( '/\b\d{1,2}\/\d{1,2}\/\d{2,4}\b/', '', $title );

		// Remove camera model info (e.g., "Canon EOS", "iPhone 14", etc.)
		$title = preg_replace( '/\b(?:Canon|Nikon|Sony|iPhone|Samsung|Pixel)\s+[A-Z0-9\s]+/i', '', $title );

		// Remove common separators and extra whitespace
		$title = preg_replace( '/\s*[-–—|•·,:]\s*/', ' ', $title );
		$title = preg_replace( '/\s+/', ' ', $title );

		// Trim whitespace
		$title = trim( $title );

		return $title;
	}

	/**
	 * Build a consistent user agent for Google requests.
	 *
	 * @return string
	 */
	private function get_default_user_agent() {
		return 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' );
	}

	/**
	 * Format EXIF numeric values for display.
	 *
	 * @param mixed $value Numeric-like value.
	 * @return string
	 */
	private function format_exif_number( $value ) {
		$number = floatval( $value );
		if ( floor( $number ) === $number ) {
			return (string) intval( $number );
		}

		$text = rtrim( rtrim( number_format( $number, 1, '.', '' ), '0' ), '.' );
		return str_replace( '.', ',', $text );
	}

	/**
	 * Whether a string looks like an original image filename (not a URL or path).
	 *
	 * @param string $fn Candidate.
	 * @return bool
	 */
	private function is_plausible_original_filename( $fn ) {
		if ( $fn === '' || strlen( $fn ) > 220 ) {
			return false;
		}
		if ( preg_match( '/https?:\/\//i', $fn ) || strpos( $fn, '//' ) !== false ) {
			return false;
		}
		if ( preg_match( '#[\/\\\\]#', $fn ) ) {
			return false;
		}
		return (bool) preg_match( '/\.(?:jpe?g|png|heic|webp)$/iu', $fn );
	}

	/**
	 * Prefer filenames that look like camera exports (YAPA_, DSC, long basenames).
	 *
	 * @param string $fn Candidate.
	 * @return int Score (higher is better).
	 */
	private function score_filename_candidate( $fn ) {
		$score = 1 + min( 15, (int) ( strlen( $fn ) / 12 ) );
		if ( preg_match( '/YAPA\d{4}-\d{2}-\d{2}/i', $fn ) ) {
			$score += 60;
		}
		if ( preg_match( '/^[A-Za-z]{3,}\d{4}-\d{2}-\d{2}/', $fn ) ) {
			$score += 45;
		}
		if ( preg_match( '/^\w+\d{4}-\d{2}-\d{2}_/', $fn ) ) {
			$score += 40;
		}
		if ( strpos( $fn, '_' ) !== false ) {
			$score += 22;
		}
		if ( preg_match( '/^(DSC|IMG|DJI_|PXL_|Photo_|SAM_|_MG)/i', $fn ) ) {
			$score += 18;
		}
		return $score;
	}

	/**
	 * Extract original filename for one media id from embedded page JSON.
	 * Google changes field numbers; scanning a window after the id is more stable than one global regex.
	 *
	 * @param string $html     Full HTML/JS payload.
	 * @param string $media_id AF1Qip… id.
	 * @return string Filename or empty string.
	 */
	private function extract_filename_for_media_id( $html, $media_id ) {
		if ( $media_id === '' ) {
			return '';
		}
		$needle = '["' . $media_id . '"';
		$pos    = strpos( $html, $needle );
		if ( false === $pos ) {
			$needle = '"' . $media_id . '"';
			$pos    = strpos( $html, $needle );
			if ( false === $pos ) {
				return '';
			}
		}
		$chunk = substr( $html, $pos, 35000 );

		// Known historical field (still present on some responses).
		if ( preg_match( '/"101428965"\s*:\s*\[\s*(?:\d+|null)\s*,\s*"([^"]+)"/', $chunk, $m ) ) {
			if ( $this->is_plausible_original_filename( $m[1] ) ) {
				return $m[1];
			}
		}

		// Numeric protobuf-style keys: "12345":[0,"file.jpg"] or with null.
		if ( preg_match_all( '/"(\d{5,12})"\s*:\s*\[\s*(?:\d+|null)\s*,\s*"([^"]*\.(?:jpg|jpeg|png|heic|webp))"\]/iu', $chunk, $all, PREG_SET_ORDER ) ) {
			$best       = '';
			$best_score = 0;
			foreach ( $all as $row ) {
				$fn = $row[2];
				if ( ! $this->is_plausible_original_filename( $fn ) ) {
					continue;
				}
				$score = $this->score_filename_candidate( $fn );
				if ( $score > $best_score ) {
					$best_score = $score;
					$best       = $fn;
				}
			}
			if ( $best !== '' ) {
				return $best;
			}
		}

		// Last resort: quoted *.ext strings in the same window (needs a strong score to avoid noise).
		if ( preg_match_all( '/"([A-Za-z0-9][^"]{0,180}\.(?:jpg|jpeg|png|heic|webp))"/u', $chunk, $q, PREG_SET_ORDER ) ) {
			$best       = '';
			$best_score = 0;
			foreach ( $q as $row ) {
				$fn = $row[1];
				if ( ! $this->is_plausible_original_filename( $fn ) ) {
					continue;
				}
				$score = $this->score_filename_candidate( $fn );
				if ( $score > $best_score ) {
					$best_score = $score;
					$best       = $fn;
				}
			}
			if ( $best !== '' && $best_score >= 30 ) {
				return $best;
			}
		}

		return '';
	}

	/**
	 * Build camera/exif display strings from a Google EXIF tuple.
	 *
	 * @param string $make     Camera make.
	 * @param string $model    Camera model.
	 * @param mixed  $focal    Focal length.
	 * @param mixed  $aperture Aperture value.
	 * @param mixed  $iso      ISO value.
	 * @param mixed  $shutter  Exposure time in seconds.
	 * @return array{camera:string,exif:string}
	 */
	private function build_camera_exif_strings( $make, $model, $focal, $aperture, $iso, $shutter ) {
		$camera = trim( trim( (string) $make ) . ' ' . trim( (string) $model ) );
		$parts  = array();

		if ( '' !== (string) $aperture ) {
			$parts[] = 'ƒ/' . $this->format_exif_number( $aperture );
		}

		$shutter = floatval( $shutter );
		if ( $shutter > 0 ) {
			$parts[] = $shutter < 1 ? '1/' . round( 1 / $shutter ) : $this->format_exif_number( $shutter ) . 's';
		}

		if ( '' !== (string) $focal ) {
			$parts[] = $this->format_exif_number( $focal ) . ' mm';
		}

		if ( intval( $iso ) > 0 ) {
			$parts[] = 'ISO' . intval( $iso );
		}

		return array(
			'camera' => $camera,
			'exif'   => implode( ' ', $parts ),
		);
	}

	/**
	 * Extract Google batchexecute context from the bootstrap HTML.
	 *
	 * @param string $html HTML document.
	 * @return array{f_sid:string,bl:string,hl:string}
	 */
	private function extract_google_rpc_context( $html ) {
		$f_sid = '';
		$bl    = '';

		if ( preg_match( '/"FdrFJe":"([^"]+)"/', $html, $match ) ) {
			$f_sid = $match[1];
		}

		if ( preg_match( '/"cfb2h":"([^"]+)"/', $html, $match ) ) {
			$bl = $match[1];
		}

		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$hl     = strtolower( substr( str_replace( '_', '-', (string) $locale ), 0, 2 ) );
		if ( '' === $hl ) {
			$hl = 'en';
		}

		return array(
			'f_sid' => $f_sid,
			'bl'    => $bl,
			'hl'    => $hl,
		);
	}

	/**
	 * Extract shared album id/key from the public album URL.
	 *
	 * @param string $album_url Shared album URL.
	 * @return array{share_id:string,share_key:string}
	 */
	private function extract_share_context( $album_url ) {
		$share_id  = '';
		$share_key = '';

		if ( preg_match( '#/share/([^/?]+)#', $album_url, $match ) ) {
			$share_id = $match[1];
		}

		$query = wp_parse_url( $album_url, PHP_URL_QUERY );
		if ( is_string( $query ) && '' !== $query ) {
			parse_str( $query, $params );
			if ( ! empty( $params['key'] ) && is_string( $params['key'] ) ) {
				$share_key = $params['key'];
			}
		}

		return array(
			'share_id'  => $share_id,
			'share_key' => $share_key,
		);
	}

	/**
	 * Decode a Google batchexecute response and return the payload for one RPC.
	 *
	 * @param string $body   Raw response body.
	 * @param string $rpc_id RPC identifier to extract.
	 * @return array
	 */
	private function decode_batchexecute_payload( $body, $rpc_id ) {
		foreach ( preg_split( "/\r?\n/", (string) $body ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '[' !== $line[0] ) {
				continue;
			}

			$decoded = json_decode( $line, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}

			foreach ( $decoded as $entry ) {
				if ( ! is_array( $entry ) || count( $entry ) < 3 ) {
					continue;
				}

				if ( 'wrb.fr' !== $entry[0] || $rpc_id !== $entry[1] || ! is_string( $entry[2] ) ) {
					continue;
				}

				$payload = json_decode( $entry[2], true );
				if ( is_array( $payload ) ) {
					return $payload;
				}
			}
		}

		return array();
	}

	/**
	 * Convert one `fDcn4b` details row into the plugin photo shape.
	 *
	 * @param array $row Decoded Google photo details row.
	 * @return array
	 */
	private function extract_photo_details_from_rpc_row( $row ) {
		if ( ! is_array( $row ) ) {
			return array();
		}

		$details = array(
			'filename'        => '',
			'timestamp'       => '',
			'timezone_offset' => '',
			'width'           => 0,
			'height'          => 0,
			'camera'          => '',
			'exif'            => '',
			'owner'           => '',
		);

		if ( isset( $row[2] ) && is_string( $row[2] ) && $this->is_plausible_original_filename( $row[2] ) ) {
			$details['filename'] = $row[2];
		}

		if ( isset( $row[3], $row[4] ) && is_numeric( $row[3] ) && is_numeric( $row[4] ) ) {
			$details['timestamp']       = floatval( $row[3] ) + floatval( $row[4] );
			$details['timezone_offset'] = intval( $row[4] );
		}

		if ( isset( $row[6] ) && is_numeric( $row[6] ) ) {
			$details['width'] = intval( $row[6] );
		}

		if ( isset( $row[7] ) && is_numeric( $row[7] ) ) {
			$details['height'] = intval( $row[7] );
		}

		if ( isset( $row[23] ) && is_array( $row[23] ) ) {
			$make     = isset( $row[23][0] ) ? $row[23][0] : '';
			$model    = isset( $row[23][1] ) ? $row[23][1] : '';
			$focal    = isset( $row[23][3] ) ? $row[23][3] : '';
			$aperture = isset( $row[23][4] ) ? $row[23][4] : '';
			$iso      = isset( $row[23][5] ) ? $row[23][5] : '';
			$shutter  = isset( $row[23][6] ) ? $row[23][6] : '';
			$exif     = $this->build_camera_exif_strings( $make, $model, $focal, $aperture, $iso, $shutter );

			$details['camera'] = $exif['camera'];
			$details['exif']   = $exif['exif'];
		}

		if (
			isset( $row[28][11][0] ) &&
			is_string( $row[28][11][0] ) &&
			'' !== trim( $row[28][11][0] )
		) {
			$details['owner'] = 'Shared by ' . trim( $row[28][11][0] );
		}

		return $details;
	}

	/**
	 * Fetch one photo details payload from Google Photos `fDcn4b`.
	 *
	 * @param string $photo_id       Photo id.
	 * @param string $album_url      Shared album URL.
	 * @param array  $rpc_context    Bootstrap RPC context.
	 * @param array  $share_context  Shared album id/key.
	 * @param int    $request_id     Request id suffix for Google batching.
	 * @return array
	 */
	private function fetch_photo_details_from_rpc( $photo_id, $album_url, $rpc_context, $share_context, $request_id ) {
		if (
			'' === $photo_id ||
			empty( $rpc_context['f_sid'] ) ||
			empty( $rpc_context['bl'] ) ||
			empty( $share_context['share_id'] ) ||
			empty( $share_context['share_key'] )
		) {
			return array();
		}

		$query = http_build_query(
			array(
				'rpcids'      => 'fDcn4b',
				'source-path' => '/share/' . $share_context['share_id'] . '/photo/' . $photo_id,
				'f.sid'       => $rpc_context['f_sid'],
				'bl'          => $rpc_context['bl'],
				'hl'          => $rpc_context['hl'],
				'soc-app'     => '165',
				'soc-platform'=> '1',
				'soc-device'  => '1',
				'_reqid'      => strval( $request_id ),
				'rt'          => 'c',
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);

		$request_payload = array(
			array(
				array(
					'fDcn4b',
					wp_json_encode( array( $photo_id, null, $share_context['share_key'], null, null, array( 2 ) ) ),
					null,
					'1',
				),
			),
		);

		$response = wp_remote_post(
			'https://photos.google.com/_/PhotosUi/data/batchexecute?' . $query,
			array(
				'timeout'    => 10,
				'user-agent' => $this->get_default_user_agent(),
				'headers'    => array(
					'Content-Type' => 'application/x-www-form-urlencoded;charset=UTF-8',
					'Origin'       => 'https://photos.google.com',
					'Referer'      => $album_url,
				),
				'body'       => 'f.req=' . rawurlencode( wp_json_encode( $request_payload ) ) . '&',
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$payload = $this->decode_batchexecute_payload( wp_remote_retrieve_body( $response ), 'fDcn4b' );
		if ( empty( $payload[0] ) ) {
			return array();
		}

		return $this->extract_photo_details_from_rpc_row( $payload[0] );
	}

	/**
	 * Fill missing filename/info fields from the Google Photos details RPC.
	 *
	 * @param array  $photos    Extracted photo rows.
	 * @param string $html      Shared album HTML.
	 * @param string $album_url Shared album URL.
	 * @return void
	 */
	private function resolve_missing_photo_details_from_rpc( &$photos, $html, $album_url ) {
		$rpc_context   = $this->extract_google_rpc_context( $html );
		$share_context = $this->extract_share_context( $album_url );
		$request_id    = 100000;

		if (
			empty( $rpc_context['f_sid'] ) ||
			empty( $rpc_context['bl'] ) ||
			empty( $share_context['share_id'] ) ||
			empty( $share_context['share_key'] )
		) {
			return;
		}

		foreach ( $photos as &$photo ) {
			$needs_rpc = empty( $photo['filename'] ) ||
				empty( $photo['camera'] ) ||
				empty( $photo['exif'] ) ||
				empty( $photo['width'] ) ||
				empty( $photo['height'] );

			if ( ! $needs_rpc || empty( $photo['id'] ) ) {
				continue;
			}

			$details    = $this->fetch_photo_details_from_rpc( $photo['id'], $album_url, $rpc_context, $share_context, $request_id );
			$request_id += 100000;

			if ( empty( $details ) ) {
				continue;
			}

			if ( ! empty( $details['filename'] ) ) {
				$photo['filename'] = $details['filename'];
			}
			if ( ! empty( $details['timestamp'] ) ) {
				$photo['timestamp'] = $details['timestamp'];
			}
			if ( isset( $details['timezone_offset'] ) && '' !== $details['timezone_offset'] ) {
				$photo['timezone_offset'] = intval( $details['timezone_offset'] );
			}
			if ( ! empty( $details['width'] ) ) {
				$photo['width'] = $details['width'];
			}
			if ( ! empty( $details['height'] ) ) {
				$photo['height'] = $details['height'];
			}
			if ( ! empty( $details['camera'] ) ) {
				$photo['camera'] = $details['camera'];
			}
			if ( ! empty( $details['exif'] ) ) {
				$photo['exif'] = $details['exif'];
			}
			if ( ! empty( $details['owner'] ) ) {
				$photo['owner'] = $details['owner'];
			}
		}
		unset( $photo );
	}

	/**
	 * Build the original-download URL used by Google Photos.
	 *
	 * @param string $photo_url Googleusercontent base URL.
	 * @return string
	 */
	private function build_original_download_url( $photo_url ) {
		if ( empty( $photo_url ) ) {
			return '';
		}

		return preg_replace( '/=[^?&]*$/', '', $photo_url ) . '=s0-d-ip';
	}

	/**
	 * Extract the original filename from a Content-Disposition header.
	 *
	 * @param string $header Header value.
	 * @return string
	 */
	private function extract_filename_from_content_disposition( $header ) {
		if ( empty( $header ) || ! is_string( $header ) ) {
			return '';
		}

		if ( preg_match( "/filename\\*=(?:UTF-8''|utf-8'')([^;]+)/", $header, $match ) ) {
			$filename = rawurldecode( trim( $match[1], "\"'" ) );
			if ( $this->is_plausible_original_filename( $filename ) ) {
				return $filename;
			}
		}

		if ( preg_match( '/filename="?([^";]+)"?/i', $header, $match ) ) {
			$filename = trim( $match[1] );
			if ( $this->is_plausible_original_filename( $filename ) ) {
				return $filename;
			}
		}

		return '';
	}

	/**
	 * Resolve missing filenames from Google download headers.
	 *
	 * @param array  $photos    Extracted photo rows.
	 * @param string $album_url Original shared album URL for Referer.
	 * @return void
	 */
	private function resolve_missing_filenames_from_headers( &$photos, $album_url ) {
		foreach ( $photos as &$photo ) {
			if ( ! empty( $photo['filename'] ) || empty( $photo['url'] ) ) {
				continue;
			}

			$download_url = $this->build_original_download_url( $photo['url'] );
			if ( empty( $download_url ) ) {
				continue;
			}

			$response = wp_remote_request(
				$download_url,
				array(
					'method'      => 'HEAD',
					'timeout'     => 10,
					'redirection' => 5,
					'user-agent'  => $this->get_default_user_agent(),
					'headers'     => array(
						'Referer' => $album_url,
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				continue;
			}

			$filename = $this->extract_filename_from_content_disposition(
				wp_remote_retrieve_header( $response, 'content-disposition' )
			);

			if ( $filename !== '' ) {
				$photo['filename'] = $filename;
			}
		}
		unset( $photo );
	}

	/**
	 * Extract photo data from HTML content
	 * Uses multiple extraction strategies for robustness
	 *
	 * @param string $html      HTML content
	 * @param string $album_url Shared album URL.
	 * @return array Photo data objects [['url' => ..., 'filename' => ...], ...]
	 */
	private function extract_photos( $html, $album_url = '' ) {
		$photos = array();

		// Stage 1: Extract basic photo objects (URL, ID, Timestamp, TZ Offset)
		// We look for the pattern: ["ID", ["URL", W, H, ...], TIMESTAMP, "DEDUP", TZ_OFFSET, ...
		// Using a more robust regex that handles nested arrays in the URL block
		if ( preg_match_all( '/\[\"(AF1Qip[^\"]+)\"\s*,\s*\[\"(https?:\/\/[^\"]+googleusercontent\.com[^\"]+)\"\s*,\s*(\d+)\s*,\s*(\d+).*?\]\s*,\s*(\d{10,13})\s*,\s*\"[^\"]*\"\s*,\s*(\d+)/is', $html, $matches ) ) {
			foreach ( $matches[1] as $index => $id ) {
				$url       = $matches[2][ $index ];
				$width     = intval( $matches[3][ $index ] );
				$height    = intval( $matches[4][ $index ] );
				$timestamp = $matches[5][ $index ];
				$tz_offset = $matches[6][ $index ];
				$url       = preg_replace( '/=[^&]*$/', '', $url );
				
				if ( ! isset( $photos[ $url ] ) ) {
					$adjusted_ts = floatval($timestamp) + floatval($tz_offset);

					$photos[ $url ] = array(
						'url'             => $url,
						'id'              => $id,
						'timestamp'       => $adjusted_ts,
						'timezone_offset' => intval( $tz_offset ),
						'width'           => $width,
						'height'          => $height,
						'filename'        => '',
						'camera'          => '',
						'exif'            => '',
						'owner'           => '',
						'owner_id'        => '',
					);
				}
			}
		}

		// Stage 2: Enrichment (EXIF, Filenames, Owners)
		// We perform a global search for metadata blocks and map them back to photos by ID
		
		// 1. EXIF Data: [width, height, 1, null, ["Make", "Model", null, focal, aperture, iso, shutter, ...]]
		if ( preg_match_all( '/\[\"(AF1Qip[^\"]+)\".*?\[\d+\s*,\s*\d+\s*,\s*1\s*,\s*null\s*,\s*\[\"([^\"]+)\"\s*,\s*\"([^\"]+)\"\s*,\s*null\s*,\s*([\d\.]+)\s*,\s*([\d\.]+)\s*,\s*(\d+)\s*,\s*([\d\.]+)/is', $html, $matches ) ) {
			$exif_map = array();
			foreach ( $matches[1] as $index => $id ) {
				$make     = $matches[2][ $index ];
				$model    = $matches[3][ $index ];
				$focal    = $matches[4][ $index ];
				$aperture = $matches[5][ $index ];
				$iso      = $matches[6][ $index ];
				$shutter  = $matches[7][ $index ];

				$exif_map[ $id ] = $this->build_camera_exif_strings( $make, $model, $focal, $aperture, $iso, $shutter );
			}
			
			foreach ( $photos as &$photo ) {
				if ( isset( $exif_map[ $photo['id'] ] ) ) {
					$photo['camera'] = $exif_map[ $photo['id'] ]['camera'];
					$photo['exif']   = $exif_map[ $photo['id'] ]['exif'];
				}
			}
		}

		// 2. Filenames/Descriptions from "101428965" key (legacy global pairing).
		if ( preg_match_all( '/\[\"(AF1Qip[^\"]+)\".*?\"101428965\"\s*:\s*\[\s*(?:\d+|null)\s*,\s*\"([^\"]+)\"\]/is', $html, $matches ) ) {
			$fn_map = array();
			foreach ( $matches[1] as $i => $id ) {
				$fn_map[ $id ] = $matches[2][ $i ];
			}
			foreach ( $photos as &$photo ) {
				if ( isset( $fn_map[ $photo['id'] ] ) && $this->is_plausible_original_filename( $fn_map[ $photo['id'] ] ) ) {
					$photo['filename'] = $fn_map[ $photo['id'] ];
				}
			}
			unset( $photo );
		}

		// 2b. Per-photo window scan when global pairing fails (Google JSON shape changes).
		foreach ( $photos as &$photo ) {
			if ( ! empty( $photo['filename'] ) || empty( $photo['id'] ) ) {
				continue;
			}
			$found = $this->extract_filename_for_media_id( $html, $photo['id'] );
			if ( $found !== '' ) {
				$photo['filename'] = $found;
			}
		}
		unset( $photo );

		// 3. Owner IDs
		if ( preg_match_all( '/\[\"(AF1Qip[^\"]+)\".*?\[\"(AF1QipNY[^\"]+)\"\]/is', $html, $matches ) ) {
			$owner_ref_map = array_combine( $matches[1], $matches[2] );
			foreach ( $photos as &$photo ) {
				if ( isset( $owner_ref_map[ $photo['id'] ] ) ) {
					$photo['owner_id'] = $owner_ref_map[ $photo['id'] ];
				}
			}
		}

		// 4. Owner Names mapping
		if ( preg_match_all( '/\[\"(AF1QipNY[^\"]+)\"\s*,\s*\"[^\"]+\"\s*,\s*null\s*,\s*null\s*,\s*null\s*,\s*\[\"[^\"]+\"\s*,\s*\"[^\"]+\"\]\s*,\s*null\s*,\s*null\s*,\s*null\s*,\s*null\s*,\s*null\s*,\s*\[\"([^\"]+)\"/i', $html, $matches ) ) {
			$owner_names = array_combine( $matches[1], $matches[2] );
			foreach ( $photos as &$photo ) {
				if ( ! empty( $photo['owner_id'] ) && isset( $owner_names[ $photo['owner_id'] ] ) ) {
					$photo['owner'] = 'Shared by ' . $owner_names[ $photo['owner_id'] ];
				}
			}
		}

		// fDcn4b is the same details RPC the info panel uses and exposes the real basename.
		$this->resolve_missing_photo_details_from_rpc( $photos, $html, $album_url );

		// Last resort only: original download headers still expose the basename if RPC details fail.
		$this->resolve_missing_filenames_from_headers( $photos, $album_url );

		// Final cleanup: normalize derived display fields.
		foreach ( $photos as &$photo ) {
			$info = array();
			if ( ! empty( $photo['camera'] ) ) {
				$info[] = $photo['camera'];
			}
			if ( ! empty( $photo['exif'] ) ) {
				$info[] = $photo['exif'];
			}
			if ( ! empty( $photo['owner'] ) ) {
				$info[] = $photo['owner'];
			}
			if ( ! empty( $photo['width'] ) && ! empty( $photo['height'] ) ) {
				$photo['megapixels'] = round( ( intval( $photo['width'] ) * intval( $photo['height'] ) ) / 1000000, 1 );
			} else {
				$photo['megapixels'] = 0;
			}
			$photo['info_combined'] = implode(' • ', $info);
		}

		// Strategy 5: Absolute fallback if no photos found yet
		if ( empty( $photos ) ) {
			if ( preg_match_all( '/\"(https?:\/\/[^\"]+googleusercontent\.com[^\"]+)\"\s*,\s*(\d+)\s*,\s*(\d+)/i', $html, $matches ) ) {
				foreach ( $matches[1] as $url ) {
					$url = preg_replace( '/=[^&]*$/', '', $url );
					if ( ! isset( $photos[ $url ] ) ) {
						$photos[ $url ] = array(
							'url'             => $url,
							'filename'        => '',
							'timestamp'       => '',
							'timezone_offset' => '',
							'camera'          => '',
							'exif'            => '',
							'width'           => 0,
							'height'          => 0,
							'megapixels'      => 0,
							'owner'           => '',
							'info_combined'   => '',
						);
					}
				}
			}
		}

		return array_values( $photos );
	}
}
