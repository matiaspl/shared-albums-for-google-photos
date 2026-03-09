<?php
/**
 * Data Provider: Fetches and extracts photo data from Google Photos
 *
 * Input Phase - Responsible for getting data from Google Photos
 *
 * @package JZSA_Shared_Albums
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Google Photos Provider Class
 */
class JZSA_Data_Provider {

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
				'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
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
		$photos = $this->extract_photos( $html );

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
	 * Extract photo data from HTML content
	 * Uses multiple extraction strategies for robustness
	 *
	 * @param string $html HTML content
	 * @return array Photo data objects [['url' => ..., 'filename' => ...], ...]
	 */
	private function extract_photos( $html ) {
		$photos = array();

		// Strategy 1: Extract from JSON-like structure in AF_initDataCallback
		// Updated pattern to match the observed data structure: ["ID", ["URL", width, height, ...], timestamp, "short_id", ...]
		if ( preg_match_all( '/\[\"([^\"]+)\"\s*,\s*\[\"(https?:\/\/[^\"]+googleusercontent\.com[^\"]+)\"\s*,\s*\d+\s*,\s*\d+/i', $html, $matches ) ) {
			foreach ( $matches[1] as $index => $id ) {
				$url = $matches[2][ $index ];
				$url = preg_replace( '/=[^&]*$/', '', $url );
				
				// Try to find a filename-like string in the surrounding context of this ID
				// Often filenames appear later in the structure or as separate entries
				$filename = '';
				if ( preg_match( '/\[\"' . preg_quote( $id, '/' ) . '\"\s*,\s*\"([^\"]+\.(?:jpg|jpeg|png|gif|webp|mp4|mov|heic))\"/i', $html, $fn_match ) ) {
					$filename = $fn_match[1];
				}

				if ( ! isset( $photos[ $url ] ) ) {
					$photos[ $url ] = array(
						'url'      => $url,
						'filename' => $filename,
					);
				}
			}
		}

		// Strategy 2: Original robust method for URLs followed by dimensions
		if ( preg_match_all( '/\"(https?:\/\/[^\"]+googleusercontent\.com[^\"]+)\"\s*,\s*\d+\s*,\s*\d+/i', $html, $matches ) ) {
			foreach ( $matches[1] as $url ) {
				$url = preg_replace( '/=[^&]*$/', '', $url );
				if ( ! isset( $photos[ $url ] ) ) {
					$photos[ $url ] = array(
						'url'      => $url,
						'filename' => '',
					);
				}
			}
		}

		// Fallback for simple URL extraction
		if ( empty( $photos ) && preg_match_all( '/\[\"(https?:\/\/[^\"]+googleusercontent\.com[^\"]+)\"\]/i', $html, $matches ) ) {
			foreach ( $matches[1] as $url ) {
				$url = preg_replace( '/=[^&]*$/', '', $url );
				if ( ! isset( $photos[ $url ] ) ) {
					$photos[ $url ] = array(
						'url'      => $url,
						'filename' => '',
					);
				}
			}
		}

		return array_values( $photos );
	}
}
