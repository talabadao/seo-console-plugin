/**
 * Tabs kept in sync with `?tab=` in the URL, so a tab can be linked to and survives a reload.
 */
import { TabPanel } from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';

import { getUrlParams, setUrlParams } from '../utils/hooks';

/**
 * @param {Object}   props
 * @param {Array}    props.tabs     [ { name, title } ].
 * @param {string}   props.param    Query arg name (default "tab").
 * @param {string}   props.initial  Default tab name (default: first tab).
 * @param {Function} props.onSelect Called with the tab name when it changes.
 * @param {Function} props.children Render prop: ( tabName ) => content.
 * @return {JSX.Element} Tabs.
 */
export default function TabPanelRoute( {
	tabs,
	param = 'tab',
	initial,
	onSelect,
	children,
} ) {
	const names = tabs.map( ( t ) => t.name );
	const fallback =
		initial && names.includes( initial ) ? initial : names[ 0 ];
	const read = useCallback( () => {
		const fromUrl = getUrlParams().get( param );
		return fromUrl && names.includes( fromUrl ) ? fromUrl : fallback;
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ param, fallback, names.join( '|' ) ] );

	const [ current, setCurrent ] = useState( read );
	// Remount the uncontrolled TabPanel when history navigation changes the tab.
	const [ mountKey, setMountKey ] = useState( 0 );

	useEffect( () => {
		const onPop = () => {
			setCurrent( read() );
			setMountKey( ( k ) => k + 1 );
		};
		window.addEventListener( 'popstate', onPop );
		return () => window.removeEventListener( 'popstate', onPop );
	}, [ read ] );

	return (
		<TabPanel
			key={ mountKey }
			className="seoc-tabs"
			tabs={ tabs }
			initialTabName={ current }
			onSelect={ ( name ) => {
				if ( name !== current ) {
					setCurrent( name );
				}
				setUrlParams( { [ param ]: name === fallback ? '' : name } );
				if ( onSelect ) {
					onSelect( name );
				}
			} }
		>
			{ ( tab ) => (
				<div className="seoc-tabs__panel">{ children( tab.name ) }</div>
			) }
		</TabPanel>
	);
}
