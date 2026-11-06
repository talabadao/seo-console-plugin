/**
 * Small shared React hooks and URL helpers.
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

import { normaliseError } from '../api';

let noticeSeq = 0;

/**
 * Page-level notices. Pass `notices` and `removeNotice` to <Page>.
 *
 * addNotice( status, message, options ) → id. status: 'success' | 'info' | 'warning' | 'error'.
 * options: { id, isDismissible, actions: [ { label, onClick | url } ] }.
 *
 * @return {{notices: Array, addNotice: Function, removeNotice: Function, clearNotices: Function}} API.
 */
export function useNotices() {
	const [ notices, setNotices ] = useState( [] );
	const addNotice = useCallback( ( status, message, options = {} ) => {
		noticeSeq += 1;
		const id = options.id || `seoc-notice-${ noticeSeq }`;
		setNotices( ( list ) => [
			...list.filter( ( n ) => n.id !== id ),
			{ id, status, message, isDismissible: true, ...options },
		] );
		return id;
	}, [] );
	const removeNotice = useCallback(
		( id ) => setNotices( ( list ) => list.filter( ( n ) => n.id !== id ) ),
		[]
	);
	const clearNotices = useCallback( () => setNotices( [] ), [] );
	return { notices, addNotice, removeNotice, clearNotices };
}

/**
 * Run an async loader with loading / error state. The loader receives an AbortSignal.
 *
 * @param {Function} loader Async function( signal ).
 * @param {Array}    deps   Re-run when these change.
 * @return {{data: *, error: Object|null, loading: boolean, reload: Function, setData: Function}} State.
 */
export function useAsync( loader, deps = [] ) {
	const [ state, setState ] = useState( {
		data: null,
		error: null,
		loading: true,
	} );
	const [ tick, setTick ] = useState( 0 );
	const loaderRef = useRef( loader );
	loaderRef.current = loader;

	useEffect( () => {
		const controller =
			typeof AbortController !== 'undefined'
				? new AbortController()
				: null;
		let alive = true;
		setState( ( s ) => ( { ...s, loading: true, error: null } ) );
		Promise.resolve()
			.then( () =>
				loaderRef.current( controller ? controller.signal : undefined )
			)
			.then(
				( data ) => {
					if ( alive ) {
						setState( { data, error: null, loading: false } );
					}
				},
				( error ) => {
					const e = normaliseError( error );
					if ( alive && e.code !== 'aborted' ) {
						setState( ( s ) => ( {
							...s,
							error: e,
							loading: false,
						} ) );
					}
				}
			);
		return () => {
			alive = false;
			if ( controller ) {
				controller.abort();
			}
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ ...deps, tick ] );

	const reload = useCallback( () => setTick( ( t ) => t + 1 ), [] );
	const setData = useCallback(
		( updater ) =>
			setState( ( s ) => ( {
				...s,
				data:
					typeof updater === 'function' ? updater( s.data ) : updater,
			} ) ),
		[]
	);
	return { ...state, reload, setData };
}

/**
 * Read query args from the current URL.
 *
 * @return {URLSearchParams} Params.
 */
export function getUrlParams() {
	return new URLSearchParams( window.location.search );
}

/**
 * Update query args in the URL without reloading (an empty value removes the arg).
 *
 * @param {Object} args { key: value }.
 */
export function setUrlParams( args ) {
	const url = new URL( window.location.href );
	Object.entries( args ).forEach( ( [ key, value ] ) => {
		if ( value === undefined || value === null || value === '' ) {
			url.searchParams.delete( key );
		} else {
			url.searchParams.set( key, String( value ) );
		}
	} );
	window.history.replaceState( window.history.state, '', url.toString() );
}
