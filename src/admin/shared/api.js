/**
 * REST client + response adapters. Every assumption about the shape of a core REST response lives here,
 * so the PHP side and the UI can be aligned in one place.
 *
 * Assumed shapes (ARCHITECTURE.md §12 leaves these open):
 *
 * GET  /suggestions?status&module&type&object_type&object_id&page&per_page
 *      → { items: Suggestion[], total }
 *      Suggestion = §7.3 row with current_value / proposed_value / final_value already JSON-decoded, plus
 *      object_title, edit_link, view_link (permalink, optional), field_label (applier label(), optional).
 * POST /suggestions/{id}/approve { value?, force? } → { suggestion?, change? }
 *      errors: seoc_stale (409, data.current_value = live value), seoc_health_failed (data.checks).
 * POST /suggestions/{id}/reject → { suggestion? }
 * POST /suggestions/bulk { action, ids, force? } → { results: [ { id, ok, code?, message? } ] }
 *      (an object keyed by id is accepted too: { results: { 12: true | { code, message } } }).
 * GET  /changes?module&status&object_type&object_id&page&per_page → { items: Change[], total }
 *      Change = §7.2 row with before_value / after_value decoded, plus user_name, object_title,
 *      edit_link, field_label, reverted_by_name (optional).
 * POST /changes/{id}/revert { force? } → { change? }; error seoc_conflict (409, data.current_value).
 * GET  /settings → {
 *        general: { ai_model, ai_monthly_budget_usd, keep_data_on_uninstall, seo_plugin, safe_mode },
 *        modules: { <id>: { enabled, ...module keys } },
 *        module_info: { <id>: { title, description } }   (title/description inside modules[id] also accepted),
 *        secrets: { claude_api_key: { has, masked, source }, google_api_key: { has, masked, source } },
 *        ai_usage: { month_cost_usd, budget_usd }        (optional)
 *      }
 * POST /settings (partial body: { general?, modules?: { <id>: { enabled } }, secrets? }) → same as GET.
 * POST /settings/test-ai { model?, api_key? } → { ok, model?, message? } or WP_Error (seoc_ai_*).
 */
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import { getConfig } from './config';

/**
 * Build a REST path under the plugin namespace with query args (empty values dropped).
 *
 * @param {string} path      Route, e.g. "suggestions" or "/changes/4/revert".
 * @param {Object} query     Query args.
 * @param {string} namespace REST namespace.
 * @return {string} Path for apiFetch.
 */
export function buildPath(
	path,
	query = {},
	namespace = getConfig().restNamespace
) {
	const clean = String( path ).replace( /^\/+/, '' );
	const params = new URLSearchParams();
	Object.keys( query || {} )
		.sort()
		.forEach( ( key ) => {
			const value = query[ key ];
			if ( value === undefined || value === null || value === '' ) {
				return;
			}
			params.set(
				key,
				Array.isArray( value ) ? value.join( ',' ) : String( value )
			);
		} );
	const qs = params.toString();
	return `/${ namespace }/${ clean }${ qs ? `?${ qs }` : '' }`;
}

const FRIENDLY = {
	fetch_error: () =>
		__(
			'Could not reach your site. Check your internet connection and try again.',
			'seo-console'
		),
	invalid_json: () =>
		__(
			'The server sent an unexpected response. A PHP error or another plugin may be interfering.',
			'seo-console'
		),
	rest_no_route: () =>
		__(
			'This feature is not available on the server yet. The plugin may need to be updated.',
			'seo-console'
		),
	rest_forbidden: () =>
		__(
			'You do not have permission to do this. Ask a site administrator.',
			'seo-console'
		),
	rest_cookie_invalid_nonce: () =>
		__(
			'Your session has expired. Reload the page and try again.',
			'seo-console'
		),
};

/**
 * Normalise anything apiFetch can reject with (WP_Error JSON, network error, Error) to
 * { code, message, status, data }.
 *
 * @param {*} error Rejection value.
 * @return {{code: string, message: string, status: number|null, data: Object}} Normalised error.
 */
export function normaliseError( error ) {
	if ( error && error.seocNormalised ) {
		return error;
	}
	let code = 'seoc_unknown';
	let message = '';
	let status = null;
	let data = {};

	if ( typeof error === 'string' ) {
		message = error;
	} else if ( error && typeof error === 'object' ) {
		if ( typeof error.code === 'string' && error.code ) {
			code = error.code;
		} else if ( error.name === 'AbortError' ) {
			code = 'aborted';
		}
		if ( typeof error.message === 'string' ) {
			message = error.message;
		}
		if ( error.data && typeof error.data === 'object' ) {
			data = error.data;
			if ( Number.isFinite( Number( error.data.status ) ) ) {
				status = Number( error.data.status );
			}
		}
		if ( status === null && Number.isFinite( Number( error.status ) ) ) {
			status = Number( error.status );
		}
	}

	if ( FRIENDLY[ code ] && ( code !== 'rest_forbidden' || ! message ) ) {
		message = FRIENDLY[ code ]();
	}
	if ( ! message ) {
		message = __(
			'Something went wrong. Please try again.',
			'seo-console'
		);
	}
	// Strip any HTML a server message may contain: we render text only.
	message = message.replace( /<[^>]*>/g, '' ).trim();

	return { code, message, status, data, seocNormalised: true };
}

async function request( options ) {
	try {
		return await apiFetch( options );
	} catch ( error ) {
		throw normaliseError( error );
	}
}

/**
 * GET a plugin route.
 *
 * @param {string}      path   Route under the namespace.
 * @param {Object}      query  Query args.
 * @param {AbortSignal} signal Optional abort signal.
 * @return {Promise<*>} Parsed JSON. Rejects with a normalised error.
 */
export function apiGet( path, query = {}, signal = undefined ) {
	return request( { path: buildPath( path, query ), method: 'GET', signal } );
}

/**
 * POST to a plugin route.
 *
 * @param {string} path Route under the namespace.
 * @param {Object} data JSON body.
 * @return {Promise<*>} Parsed JSON. Rejects with a normalised error.
 */
export function apiPost( path, data = {} ) {
	return request( { path: buildPath( path ), method: 'POST', data } );
}

/* ---------------------------------------------------------------- adapters */

const toInt = ( v, fallback = 0 ) => {
	const n = parseInt( v, 10 );
	return Number.isFinite( n ) ? n : fallback;
};
const toNumberOrNull = ( v ) => {
	if ( v === null || v === undefined || v === '' ) {
		return null;
	}
	const n = Number( v );
	return Number.isFinite( n ) ? n : null;
};
const str = ( v ) => ( v === null || v === undefined ? '' : String( v ) );

/**
 * @param {*}        raw     Response body.
 * @param {Function} adapter Item adapter.
 * @return {{items: Array, total: number}} List.
 */
export function normaliseList( raw, adapter = ( x ) => x ) {
	if ( Array.isArray( raw ) ) {
		return { items: raw.map( adapter ), total: raw.length };
	}
	const items =
		raw && Array.isArray( raw.items ) ? raw.items.map( adapter ) : [];
	const total =
		raw && raw.total !== undefined
			? toInt( raw.total, items.length )
			: items.length;
	return { items, total };
}

/**
 * Remove a display suffix (e.g. an SEO plugin template ending such as " %sep% %sitename%") from a stored value.
 *
 * @param {*}      value  Value.
 * @param {string} suffix Suffix ('' = none).
 * @return {*} Value without the suffix (non-strings unchanged).
 */
export function stripDisplaySuffix( value, suffix ) {
	if ( typeof value !== 'string' || ! suffix ) {
		return value;
	}
	const bare = suffix.trim();
	const trimmed = value.trimEnd();
	return bare && trimmed.endsWith( bare )
		? trimmed.slice( 0, -bare.length ).trimEnd()
		: value;
}

/**
 * Suggestion meta conventions understood by the UI:
 * - display_suffix: hidden from the shown/edited value (the server re-appends it on approve);
 * - brand_suffix_resolved: what that suffix looks like on the site, used for length counters and previews;
 * - warnings: [ { code, message } ].
 *
 * @param {Object} raw REST item.
 * @return {Object} Normalised suggestion.
 */
export function normaliseSuggestion( raw = {} ) {
	const meta =
		raw.meta && typeof raw.meta === 'object' && ! Array.isArray( raw.meta )
			? raw.meta
			: null;
	const suffix =
		meta && typeof meta.display_suffix === 'string'
			? meta.display_suffix
			: '';
	const value = ( v ) =>
		v === undefined ? null : stripDisplaySuffix( v, suffix );
	return {
		id: toInt( raw.id ),
		createdAt: str( raw.created_at ),
		updatedAt: str( raw.updated_at ),
		module: str( raw.module ),
		type: str( raw.type ),
		objectType: str( raw.object_type ),
		objectId:
			raw.object_id === null || raw.object_id === undefined
				? null
				: toInt( raw.object_id ),
		objectTitle: str( raw.object_title ),
		editLink: str( raw.edit_link ),
		viewLink: str( raw.view_link || raw.permalink ),
		field: str( raw.field ),
		fieldLabel: str( raw.field_label ),
		currentValue: value( raw.current_value ),
		proposedValue: value( raw.proposed_value ),
		finalValue: value( raw.final_value ),
		meta,
		displaySuffix: suffix,
		displaySuffixResolved:
			meta && typeof meta.brand_suffix_resolved === 'string'
				? meta.brand_suffix_resolved
				: '',
		warnings:
			meta && Array.isArray( meta.warnings )
				? meta.warnings.filter(
						( w ) => w && typeof w.message === 'string'
				  )
				: [],
		rationale: str( raw.rationale ),
		confidence: toNumberOrNull( raw.confidence ),
		source: str( raw.source ),
		model: str( raw.model ),
		status: str( raw.status ) || 'pending',
		changeId: raw.change_id ? toInt( raw.change_id ) : null,
		error: str( raw.error ),
	};
}

export function normaliseChange( raw = {} ) {
	return {
		id: toInt( raw.id ),
		createdAt: str( raw.created_at ),
		userId: toInt( raw.user_id ),
		userName: str( raw.user_name ),
		source: str( raw.source ),
		module: str( raw.module ),
		type: str( raw.type ),
		objectType: str( raw.object_type ),
		objectId:
			raw.object_id === null || raw.object_id === undefined
				? null
				: toInt( raw.object_id ),
		objectTitle: str( raw.object_title ),
		editLink: str( raw.edit_link ),
		field: str( raw.field ),
		fieldLabel: str( raw.field_label ),
		before: raw.before_value === undefined ? null : raw.before_value,
		after: raw.after_value === undefined ? null : raw.after_value,
		status: str( raw.status ) || 'applied',
		error: str( raw.error ),
		suggestionId: raw.suggestion_id ? toInt( raw.suggestion_id ) : null,
		batchId: str( raw.batch_id ),
		note: str( raw.note ),
		revertedAt: str( raw.reverted_at ),
		revertedByName: str( raw.reverted_by_name ),
	};
}

/**
 * Normalise per-id bulk results to an array of { id, ok, code, message }.
 *
 * @param {*} raw Response body.
 * @return {Array} Results.
 */
export function normaliseBulkResults( raw ) {
	const results = raw && raw.results !== undefined ? raw.results : raw;
	if ( Array.isArray( results ) ) {
		return results.map( ( r ) => ( {
			id: toInt( r.id ),
			ok: !! r.ok && ! r.code,
			code: str( r.code ),
			message: str( r.message ),
		} ) );
	}
	if ( results && typeof results === 'object' ) {
		return Object.keys( results ).map( ( id ) => {
			const r = results[ id ];
			const failed =
				r && typeof r === 'object' && ( r.code || r.ok === false );
			return {
				id: toInt( id ),
				ok: !! r && ! failed,
				code: failed ? str( r.code ) : '',
				message: failed ? str( r.message ) : '',
			};
		} );
	}
	return [];
}

const SECRET_NAMES = [ 'claude_api_key', 'google_api_key' ];

function normaliseSecret( raw ) {
	const r = raw && typeof raw === 'object' ? raw : {};
	const source = [ 'constant', 'stored', 'none' ].includes( r.source )
		? r.source
		: 'none';
	return {
		has: !! r.has || source === 'constant' || source === 'stored',
		masked: str( r.masked ),
		source,
	};
}

export function normaliseSettings( raw = {} ) {
	const g = ( raw && raw.general ) || {};
	const modulesRaw = ( raw && raw.modules ) || {};
	const info = ( raw && ( raw.module_info || raw.moduleInfo ) ) || {};
	const modules = {};
	Object.keys( { ...modulesRaw, ...info } ).forEach( ( id ) => {
		const m = modulesRaw[ id ] || {};
		const i = info[ id ] || {};
		modules[ id ] = {
			id,
			enabled: !! m.enabled,
			title: str( i.title || m.title ) || id,
			description: str( i.description || m.description ),
		};
	} );
	const secrets = {};
	SECRET_NAMES.forEach( ( name ) => {
		secrets[ name ] = normaliseSecret(
			raw && raw.secrets ? raw.secrets[ name ] : null
		);
	} );
	const usage = ( raw && ( raw.ai_usage || raw.aiUsage ) ) || null;
	return {
		general: {
			ai_model: str( g.ai_model ) || 'claude-opus-5-5',
			ai_monthly_budget_usd: toInt( g.ai_monthly_budget_usd, 20 ),
			keep_data_on_uninstall: !! g.keep_data_on_uninstall,
			seo_plugin: str( g.seo_plugin ) || 'auto',
			safe_mode: !! g.safe_mode,
		},
		modules,
		secrets,
		aiUsage: usage
			? {
					monthCostUsd: toNumberOrNull( usage.month_cost_usd ),
					budgetUsd: toNumberOrNull( usage.budget_usd ),
			  }
			: null,
	};
}

/* --------------------------------------------------------------- endpoints */

export async function getSuggestions( query = {}, signal ) {
	return normaliseList(
		await apiGet( 'suggestions', query, signal ),
		normaliseSuggestion
	);
}

export function approveSuggestion( id, { value, force } = {} ) {
	const body = {};
	if ( value !== undefined ) {
		body.value = value;
	}
	if ( force ) {
		body.force = true;
	}
	return apiPost( `suggestions/${ toInt( id ) }/approve`, body );
}

export function rejectSuggestion( id ) {
	return apiPost( `suggestions/${ toInt( id ) }/reject` );
}

export async function bulkSuggestions( action, ids ) {
	return normaliseBulkResults(
		await apiPost( 'suggestions/bulk', {
			action,
			ids: ids.map( ( i ) => toInt( i ) ),
		} )
	);
}

export async function getChanges( query = {}, signal ) {
	return normaliseList(
		await apiGet( 'changes', query, signal ),
		normaliseChange
	);
}

export function revertChange( id, { force } = {} ) {
	return apiPost(
		`changes/${ toInt( id ) }/revert`,
		force ? { force: true } : {}
	);
}

export async function getSettings( signal ) {
	return normaliseSettings( await apiGet( 'settings', {}, signal ) );
}

/**
 * Save settings. If the response leaves out a section (e.g. `secrets` or `ai_usage`), the matching part
 * of `previous` is kept instead of being reset to "not set".
 *
 * @param {Object} patch    Partial body: { general?, modules?, secrets? }.
 * @param {Object} previous Normalised settings before the save (optional).
 * @return {Promise<Object>} Normalised settings after save.
 */
export async function saveSettings( patch, previous = null ) {
	const raw = ( await apiPost( 'settings', patch ) ) || {};
	const next = normaliseSettings( raw );
	if ( ! previous ) {
		return next;
	}
	const modules = { ...next.modules };
	Object.keys( modules ).forEach( ( id ) => {
		const before = previous.modules[ id ];
		if ( before && modules[ id ].title === id ) {
			modules[ id ] = {
				...modules[ id ],
				title: before.title,
				description: modules[ id ].description || before.description,
			};
		}
	} );
	return {
		general: raw.general ? next.general : previous.general,
		modules: raw.modules ? modules : previous.modules,
		secrets: raw.secrets ? next.secrets : previous.secrets,
		aiUsage: raw.ai_usage || raw.aiUsage ? next.aiUsage : previous.aiUsage,
	};
}

export async function testAi( body = {} ) {
	const raw = ( await apiPost( 'settings/test-ai', body ) ) || {};
	return {
		ok: raw.ok !== false,
		model: str( raw.model ),
		message: str( raw.message ),
	};
}
