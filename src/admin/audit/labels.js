/**
 * Plain-English labels for audit values.
 */
import { __, sprintf } from '@wordpress/i18n';

import { formatNumber } from '../shared';

export const SEVERITY_STATUS = {
	error: 'poor',
	warning: 'needs-work',
	notice: 'info',
};

export function severityLabel( severity ) {
	switch ( severity ) {
		case 'error':
			return __( 'Error', 'seo-console' );
		case 'warning':
			return __( 'Warning', 'seo-console' );
		default:
			return __( 'Notice', 'seo-console' );
	}
}

export function categoryLabel( category ) {
	const labels = {
		indexability: __( 'Indexing', 'seo-console' ),
		content: __( 'Content', 'seo-console' ),
		links: __( 'Links', 'seo-console' ),
		images: __( 'Images', 'seo-console' ),
		performance: __( 'Speed & mobile', 'seo-console' ),
		international: __( 'Languages', 'seo-console' ),
		social: __( 'Social sharing', 'seo-console' ),
		'structured-data': __( 'Structured data', 'seo-console' ),
	};
	return labels[ category ] || category;
}

export function fixableLabel( who ) {
	switch ( who ) {
		case 'ai':
			return __( 'AI can suggest a fix', 'seo-console' );
		case 'user':
			return __( 'You can fix this', 'seo-console' );
		case 'developer':
			return __( 'Needs a developer', 'seo-console' );
		default:
			return __( 'Nothing to fix', 'seo-console' );
	}
}

export function indexabilityLabel( value ) {
	const labels = {
		indexable: __( 'Indexable', 'seo-console' ),
		noindex: __( 'Noindex', 'seo-console' ),
		canonicalised: __( 'Canonical elsewhere', 'seo-console' ),
		redirect: __( 'Redirect', 'seo-console' ),
		client_error: __( 'Not found / blocked (4xx)', 'seo-console' ),
		server_error: __( 'Server error (5xx)', 'seo-console' ),
		blocked: __( 'Blocked by robots.txt', 'seo-console' ),
		not_html: __( 'Not a web page', 'seo-console' ),
		host_variant: __( 'Other domain version', 'seo-console' ),
		error: __( 'Could not load', 'seo-console' ),
		not_ok: __( 'Unusual status', 'seo-console' ),
	};
	return labels[ value ] || value || '—';
}

export function indexabilityStatus( value ) {
	if ( value === 'indexable' ) {
		return 'good';
	}
	if ( [ 'client_error', 'server_error', 'error' ].includes( value ) ) {
		return 'poor';
	}
	return 'neutral';
}

export function scoreStatus( score ) {
	if ( score === null || score === undefined ) {
		return 'unknown';
	}
	if ( score >= 80 ) {
		return 'good';
	}
	return score >= 50 ? 'needs-work' : 'poor';
}

export function runStatusLabel( status ) {
	const labels = {
		queued: __( 'Waiting to start', 'seo-console' ),
		running: __( 'Running', 'seo-console' ),
		done: __( 'Finished', 'seo-console' ),
		failed: __( 'Failed', 'seo-console' ),
		cancelled: __( 'Cancelled', 'seo-console' ),
	};
	return labels[ status ] || status;
}

/**
 * One sentence describing what a running audit is doing.
 *
 * @param {Object} progress Progress from the REST API.
 * @return {string} Sentence.
 */
export function phaseSentence( progress ) {
	if ( ! progress ) {
		return '';
	}
	switch ( progress.phase ) {
		case 'seed':
			return __(
				'Step 1 of 3: reading robots.txt and your sitemaps…',
				'seo-console'
			);
		case 'crawl':
			return sprintf(
				/* translators: 1: pages crawled, 2: page limit, 3: pages found */
				__(
					'Step 2 of 3: visiting pages — %1$s checked (limit %2$s, %3$s found so far)…',
					'seo-console'
				),
				formatNumber( progress.pages_crawled ),
				formatNumber( progress.max_pages ),
				formatNumber( progress.pages_found )
			);
		case 'finalize':
			return progress.to_check
				? sprintf(
						/* translators: 1: pages checked, 2: pages to check */
						__(
							'Step 3 of 3: checking pages against the audit rules (%1$s of %2$s)…',
							'seo-console'
						),
						formatNumber( progress.checked ),
						formatNumber( progress.to_check )
				  )
				: __(
						'Step 3 of 3: checking pages against the audit rules…',
						'seo-console'
				  );
		default:
			return '';
	}
}

/**
 * Share of the work done (0–1) for a progress bar.
 *
 * @param {Object} progress Progress.
 * @return {number} Fraction.
 */
export function progressShare( progress ) {
	if ( ! progress ) {
		return 0;
	}
	if ( progress.done ) {
		return 1;
	}
	if ( progress.phase === 'seed' ) {
		return 0.03;
	}
	if ( progress.phase === 'crawl' ) {
		const target = Math.max(
			1,
			Math.min( progress.max_pages || 1, progress.pages_found || 1 )
		);
		return 0.05 + 0.8 * Math.min( 1, progress.pages_crawled / target );
	}
	return (
		0.85 +
		0.15 *
			( progress.to_check
				? Math.min( 1, progress.checked / progress.to_check )
				: 0 )
	);
}
