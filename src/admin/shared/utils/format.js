/**
 * Formatting helpers that depend on WordPress settings (site time zone, locale).
 */
import { dateI18n, getSettings, humanTimeDiff } from '@wordpress/date';
import { __, sprintf } from '@wordpress/i18n';

import { toTime } from './chart';

/**
 * Site time zone label, e.g. "ICT" or "UTC+7".
 *
 * @return {string} Label.
 */
export function siteTimezoneLabel() {
	try {
		const tz = getSettings().timezone || {};
		if ( tz.abbr && ! /^[+-]/.test( tz.abbr ) ) {
			return tz.abbr;
		}
		const offset = Number( tz.offset );
		if ( Number.isFinite( offset ) ) {
			if ( offset === 0 ) {
				return 'UTC';
			}
			const h = Math.trunc( offset );
			const m = Math.round( Math.abs( offset - h ) * 60 );
			const mm = m ? ':' + String( m ).padStart( 2, '0' ) : '';
			return `UTC${ offset > 0 ? '+' : '-' }${ Math.abs( h ) }${ mm }`;
		}
	} catch ( e ) {
		// Fall through to UTC.
	}
	return 'UTC';
}

/**
 * "Nov 6, 2026 2:30 pm ICT" in the site's time zone. Server datetimes are UTC (§7.2).
 *
 * @param {string|number|Date} value Date.
 * @param {boolean}            time  Include the time.
 * @return {string} Formatted date, or '' when invalid.
 */
export function formatDate( value, time = true ) {
	const t = toTime( value );
	if ( t === null ) {
		return '';
	}
	const date = new Date( t );
	if ( ! time ) {
		return dateI18n( 'M j, Y', date );
	}
	return `${ dateI18n( 'M j, Y g:i a', date ) } ${ siteTimezoneLabel() }`;
}

/**
 * "3 days ago".
 *
 * @param {string|number|Date} value Date.
 * @return {string} Relative time, or '' when invalid.
 */
export function formatRelative( value ) {
	const t = toTime( value );
	if ( t === null ) {
		return '';
	}
	return humanTimeDiff( new Date( t ), new Date() );
}

/**
 * @param {number|null} value  Amount in USD.
 * @param {number}      digits Fraction digits.
 * @return {string} "$12.40" or "—".
 */
export function formatMoney( value, digits = 2 ) {
	if (
		value === null ||
		value === undefined ||
		! Number.isFinite( Number( value ) )
	) {
		return '—';
	}
	try {
		return new Intl.NumberFormat( undefined, {
			style: 'currency',
			currency: 'USD',
			minimumFractionDigits: digits,
			maximumFractionDigits: digits,
		} ).format( Number( value ) );
	} catch ( e ) {
		return `$${ Number( value ).toFixed( digits ) }`;
	}
}

/**
 * @param {number|null} value Number.
 * @param {Object}      opts  Intl options.
 * @return {string} Formatted, or "—" when missing.
 */
export function formatNumber( value, opts = {} ) {
	if (
		value === null ||
		value === undefined ||
		value === '' ||
		! Number.isFinite( Number( value ) )
	) {
		return '—';
	}
	return new Intl.NumberFormat( undefined, opts ).format( Number( value ) );
}

/**
 * Translated label for a change / suggestion source.
 *
 * @param {string} source Source id.
 * @return {string} Label.
 */
export function sourceLabel( source ) {
	const map = {
		user: __( 'Person', 'seo-console' ),
		ai: __( 'AI', 'seo-console' ),
		rule: __( 'Rule', 'seo-console' ),
		mcp: __( 'Claude Code (MCP)', 'seo-console' ),
		system: __( 'System', 'seo-console' ),
	};
	return map[ source ] || source || __( 'Unknown', 'seo-console' );
}

/**
 * "AI · claude-opus-5-5".
 *
 * @param {string} source Source id.
 * @param {string} model  Model id.
 * @return {string} Label.
 */
export function sourceWithModel( source, model ) {
	if ( ! model ) {
		return sourceLabel( source );
	}
	return sprintf(
		/* translators: 1: source such as "AI", 2: model id */
		__( '%1$s · %2$s', 'seo-console' ),
		sourceLabel( source ),
		model
	);
}

/**
 * Translated module name, using server-provided info first.
 *
 * @param {string} id   Module id.
 * @param {Object} info Optional { title }.
 * @return {string} Name.
 */
export function moduleLabel( id, info = null ) {
	if ( info && info.title ) {
		return info.title;
	}
	const map = {
		speed: __( 'Speed', 'seo-console' ),
		audit: __( 'Site audit', 'seo-console' ),
		meta: __( 'Meta & AI suggestions', 'seo-console' ),
		schema: __( 'Schema', 'seo-console' ),
		chat: __( 'AI chat', 'seo-console' ),
		core: __( 'Core', 'seo-console' ),
	};
	return map[ id ] || id || '';
}
