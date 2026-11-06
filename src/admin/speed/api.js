/**
 * Speed module REST calls (seo-console/v1/speed/…). Shapes: see includes/Modules/Speed/SpeedRest.php.
 */
import { apiGet, apiPost } from '../shared';

export const getOverview = ( signal ) => apiGet( 'speed/overview', {}, signal );
export const startRun = () => apiPost( 'speed/runs' );
export const getRun = ( id, signal ) =>
	apiGet( `speed/runs/${ parseInt( id, 10 ) }`, {}, signal );
export const getRecommendations = ( signal ) =>
	apiGet( 'speed/recommendations', {}, signal );
export const getTweaks = ( signal ) => apiGet( 'speed/tweaks', {}, signal );
export const setTweak = ( id, enabled ) =>
	apiPost( `speed/tweaks/${ id }`, { enabled: !! enabled } );
export const getCache = ( signal ) => apiGet( 'speed/cache', {}, signal );
export const getMaintenance = ( signal ) =>
	apiGet( 'speed/maintenance', {}, signal );
export const runCleanup = ( action ) =>
	apiPost( `speed/maintenance/${ action }`, { confirm: true } );
export const getUrls = ( signal ) => apiGet( 'speed/urls', {}, signal );
export const saveUrls = ( data ) => apiPost( 'speed/urls', data );
export const refreshCrux = () => apiPost( 'speed/crux' );
