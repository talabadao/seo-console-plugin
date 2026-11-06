/**
 * Reads window.seocConfig (ARCHITECTURE.md §13) with safe defaults.
 *
 * Core keys: restNamespace, adminUrl, pluginUrl, version, page, modules ({ id: enabled }), seoPlugin,
 * cachePlugins, safeMode, aiConfigured.
 * Optional keys this UI also understands (see the report / api.js header): dashboardCards, moduleInfo,
 * checklist, googleConfigured.
 */

const DEFAULTS = {
	restNamespace: 'seo-console/v1',
	adminUrl: '/wp-admin/',
	pluginUrl: '',
	version: '',
	page: '',
	modules: {},
	moduleInfo: {},
	seoPlugin: 'none',
	cachePlugins: [],
	safeMode: false,
	aiConfigured: false,
	googleConfigured: null,
	dashboardCards: null,
	checklist: {},
};

function isPlainObject( value ) {
	return !! value && typeof value === 'object' && ! Array.isArray( value );
}

/**
 * Merge a raw config object over the defaults, dropping values of the wrong type.
 *
 * @param {Object} raw Raw config (usually window.seocConfig).
 * @return {Object} Normalised config.
 */
export function normaliseConfig( raw ) {
	const src = isPlainObject( raw ) ? raw : {};
	const out = { ...DEFAULTS };
	for ( const key of Object.keys( DEFAULTS ) ) {
		if (
			! ( key in src ) ||
			src[ key ] === null ||
			src[ key ] === undefined
		) {
			continue;
		}
		const def = DEFAULTS[ key ];
		const val = src[ key ];
		if ( typeof def === 'string' && typeof val === 'string' ) {
			out[ key ] = val;
		} else if ( typeof def === 'boolean' ) {
			out[ key ] = !! val;
		} else if ( Array.isArray( def ) && Array.isArray( val ) ) {
			out[ key ] = val;
		} else if ( isPlainObject( def ) && isPlainObject( val ) ) {
			out[ key ] = val;
		} else if ( def === null ) {
			out[ key ] = val;
		}
	}
	out.restNamespace = out.restNamespace.replace( /^\/+|\/+$/g, '' );
	if ( out.adminUrl && ! out.adminUrl.endsWith( '/' ) ) {
		out.adminUrl += '/';
	}
	if ( typeof out.seoPlugin !== 'string' || ! out.seoPlugin ) {
		out.seoPlugin = 'none';
	}
	return out;
}

let cached = null;

/**
 * The page config. Read once; call with `true` to re-read (tests).
 *
 * @param {boolean} refresh Re-read window.seocConfig.
 * @return {Object} Config.
 */
export function getConfig( refresh = false ) {
	if ( ! cached || refresh ) {
		cached = normaliseConfig(
			typeof window !== 'undefined' ? window.seocConfig : undefined
		);
	}
	return cached;
}

/**
 * Admin page slugs. Module pages are `seo-console-<id>` (§13).
 */
export const PAGE_SLUGS = {
	dashboard: 'seo-console',
	suggestions: 'seo-console-suggestions',
	changes: 'seo-console-changes',
	settings: 'seo-console-settings',
};

/**
 * Build a URL to an admin page of this plugin.
 *
 * @param {string} page Key of PAGE_SLUGS, a module id, or a full slug starting with "seo-console".
 * @param {Object} args Extra query args.
 * @return {string} URL.
 */
export function adminPageUrl( page, args = {} ) {
	let slug = PAGE_SLUGS[ page ];
	if ( ! slug ) {
		slug = page.startsWith( 'seo-console' )
			? page
			: `seo-console-${ page }`;
	}
	const params = new URLSearchParams( { page: slug } );
	for ( const [ key, value ] of Object.entries( args ) ) {
		if ( value !== undefined && value !== null && value !== '' ) {
			params.set( key, String( value ) );
		}
	}
	return `${ getConfig().adminUrl }admin.php?${ params.toString() }`;
}

/**
 * Is a module enabled according to seocConfig.modules?
 *
 * @param {string} id Module id.
 * @return {boolean} Enabled.
 */
export function isModuleEnabled( id ) {
	return !! getConfig().modules[ id ];
}
