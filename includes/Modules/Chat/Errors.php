<?php
/**
 * Turns Claude client errors into messages a non-technical user understands.
 *
 * @package SEOConsole
 */

namespace SEOConsole\Modules\Chat;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Errors {

	/**
	 * Maps a client error to a user-facing WP_Error (same code, friendly message, HTTP status in data).
	 *
	 * @param WP_Error $error Error from ClientInterface.
	 * @return WP_Error
	 */
	public static function from_client( WP_Error $error ): WP_Error {
		$code = (string) $error->get_error_code();
		switch ( $code ) {
			case 'seoc_ai_not_available':
				$message = __( 'AI is not set up or this month\'s AI budget is used up. Check the Claude API key and budget in SEO Console → Settings.', 'seo-console' );
				$status  = 503;
				break;
			case 'seoc_ai_budget':
				$message = __( 'This month\'s AI budget is used up. Raise it in SEO Console → Settings, or wait until next month.', 'seo-console' );
				$status  = 402;
				break;
			case 'seoc_ai_auth':
				$message = __( 'Claude rejected the API key. Check the key in SEO Console → Settings.', 'seo-console' );
				$status  = 503;
				break;
			case 'seoc_ai_unavailable':
			case 'seoc_ai_network':
			case 'seoc_ai_timeout':
				$message = __( 'Claude could not be reached right now. Please try again in a minute.', 'seo-console' );
				$status  = 503;
				break;
			case 'seoc_ai_refusal':
				$message = __( 'Claude declined to answer this message. Try rephrasing the question.', 'seo-console' );
				$status  = 422;
				break;
			case 'seoc_ai_truncated':
			case 'seoc_ai_max_tokens':
				$message = __( 'The answer was cut off because it was too long. Ask for a shorter answer or split the question.', 'seo-console' );
				$status  = 502;
				break;
			case 'seoc_ai_request':
				$message = __( 'Claude could not process this request. Try a shorter message or start a new conversation.', 'seo-console' );
				$status  = 400;
				break;
			default:
				$raw     = wp_strip_all_tags( $error->get_error_message() );
				$message = '' === $raw
					? __( 'The AI request failed.', 'seo-console' )
					/* translators: %s: technical error message */
					: sprintf( __( 'The AI request failed: %s', 'seo-console' ), $raw );
				$status = 502;
		}
		return new WP_Error( '' === $code ? 'seoc_ai_error' : $code, $message, array( 'status' => $status ) );
	}
}
