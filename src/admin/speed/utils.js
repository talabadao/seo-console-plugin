/**
 * Formatting and rating helpers for speed metrics.
 */
import { __ } from '@wordpress/i18n';

import { formatNumber } from '../shared';

/** Google's thresholds (p75): [ good up to, poor above ]. Overridden by the server's values when present. */
export const LIMITS = {
	lcp: [ 2500, 4000 ],
	inp: [ 200, 500 ],
	cls: [ 0.1, 0.25 ],
	fcp: [ 1800, 3000 ],
	ttfb: [ 800, 1800 ],
	tbt: [ 200, 600 ],
	si: [ 3400, 5800 ],
};

export function rateValue( metric, value, limits = LIMITS ) {
	const l = limits[ metric ];
	if ( ! l || value === null || value === undefined ) {
		return 'unknown';
	}
	if ( value <= l[ 0 ] ) {
		return 'good';
	}
	return value <= l[ 1 ] ? 'needs-work' : 'poor';
}

export function ratingToStatus( rating ) {
	switch ( rating ) {
		case 'good':
			return 'good';
		case 'ni':
			return 'needs-work';
		case 'poor':
			return 'poor';
		default:
			return 'unknown';
	}
}

export function scoreStatus( score ) {
	if ( score === null || score === undefined ) {
		return 'unknown';
	}
	if ( score >= 90 ) {
		return 'good';
	}
	return score >= 50 ? 'needs-work' : 'poor';
}

export function formatMs( ms ) {
	if ( ms === null || ms === undefined ) {
		return '—';
	}
	if ( ms >= 1000 ) {
		return `${ formatNumber( ms / 1000, {
			maximumFractionDigits: 1,
		} ) } s`;
	}
	return `${ formatNumber( Math.round( ms ) ) } ms`;
}

export function formatCls( value ) {
	if ( value === null || value === undefined ) {
		return '—';
	}
	return formatNumber( value, {
		minimumFractionDigits: 2,
		maximumFractionDigits: 3,
	} );
}

export function formatMetric( metric, value ) {
	return metric === 'cls' ? formatCls( value ) : formatMs( value );
}

export const METRICS = {
	lcp: {
		label: () => __( 'Largest Contentful Paint (LCP)', 'seo-console' ),
		short: 'LCP',
		help: () =>
			__(
				'When the biggest image or text block at the top of the page appears. Good: 2.5 s or less.',
				'seo-console'
			),
	},
	inp: {
		label: () => __( 'Interaction to Next Paint (INP)', 'seo-console' ),
		short: 'INP',
		help: () =>
			__(
				'How quickly the page reacts to taps and clicks. Good: 200 ms or less.',
				'seo-console'
			),
	},
	cls: {
		label: () => __( 'Cumulative Layout Shift (CLS)', 'seo-console' ),
		short: 'CLS',
		help: () =>
			__(
				'How much the page jumps around while loading. Good: 0.1 or less.',
				'seo-console'
			),
	},
	fcp: {
		label: () => __( 'First Contentful Paint (FCP)', 'seo-console' ),
		short: 'FCP',
		help: () =>
			__(
				'When the first text or image appears. Good: 1.8 s or less.',
				'seo-console'
			),
	},
	ttfb: {
		label: () => __( 'Time to First Byte (TTFB)', 'seo-console' ),
		short: 'TTFB',
		help: () =>
			__(
				'How long the server takes to start answering. Good: 0.8 s or less.',
				'seo-console'
			),
	},
	tbt: {
		label: () => __( 'Total Blocking Time (TBT)', 'seo-console' ),
		short: 'TBT',
		help: () =>
			__(
				'How long scripts kept the page from responding during the test. Good: 200 ms or less.',
				'seo-console'
			),
	},
};

export function strategyLabel( strategy ) {
	return strategy === 'desktop'
		? __( 'Desktop', 'seo-console' )
		: __( 'Mobile', 'seo-console' );
}

export function impactStatus( impact ) {
	if ( impact === 'high' ) {
		return 'poor';
	}
	return impact === 'medium' ? 'needs-work' : 'neutral';
}

export function impactLabel( impact ) {
	if ( impact === 'high' ) {
		return __( 'High impact', 'seo-console' );
	}
	return impact === 'medium'
		? __( 'Medium impact', 'seo-console' )
		: __( 'Low impact', 'seo-console' );
}

export function effortLabel( effort ) {
	if ( effort === 'high' ) {
		return __( 'Big job', 'seo-console' );
	}
	return effort === 'medium'
		? __( 'Some work', 'seo-console' )
		: __( 'Quick fix', 'seo-console' );
}
