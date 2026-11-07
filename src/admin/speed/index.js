/**
 * Speed page: Overview, To do, Tweaks, Cache plugin, Database, Pages tested.
 */
import { Button } from '@wordpress/components';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
	ErrorNotice,
	Loading,
	Page,
	TabPanelRoute,
	mount,
	useAsync,
	useNotices,
} from '../shared';
import {
	getCache,
	getMaintenance,
	getOverview,
	getRecommendations,
	getRun,
	getTweaks,
	getUrls,
	startRun,
} from './api';
import CacheTab from './CacheTab';
import DatabaseTab from './DatabaseTab';
import OverviewTab from './OverviewTab';
import PagesTab from './PagesTab';
import TodoTab from './TodoTab';
import TweaksTab from './TweaksTab';

const TABS = [
	{ name: 'overview', title: __( 'Overview', 'seo-console' ) },
	{ name: 'todo', title: __( 'To do', 'seo-console' ) },
	{ name: 'tweaks', title: __( 'Tweaks', 'seo-console' ) },
	{ name: 'cache', title: __( 'Cache plugin', 'seo-console' ) },
	{ name: 'database', title: __( 'Database', 'seo-console' ) },
	{ name: 'pages', title: __( 'Pages tested', 'seo-console' ) },
];

function TodoPanel( props ) {
	const recs = useAsync( ( signal ) => getRecommendations( signal ), [] );
	return <TodoTab recs={ recs } onChanged={ recs.reload } { ...props } />;
}

function TweaksPanel( props ) {
	const tweaks = useAsync( ( signal ) => getTweaks( signal ), [] );
	return <TweaksTab tweaks={ tweaks } { ...props } />;
}

function CachePanel() {
	const cache = useAsync( ( signal ) => getCache( signal ), [] );
	return <CacheTab cache={ cache } />;
}

function DatabasePanel( props ) {
	const maintenance = useAsync( ( signal ) => getMaintenance( signal ), [] );
	return <DatabaseTab maintenance={ maintenance } { ...props } />;
}

function PagesPanel( props ) {
	const urls = useAsync( ( signal ) => getUrls( signal ), [] );
	return <PagesTab urls={ urls } { ...props } />;
}

function App() {
	const { notices, addNotice, removeNotice } = useNotices();
	const overview = useAsync( ( signal ) => getOverview( signal ), [] );
	const [ activeRun, setActiveRun ] = useState( null );
	const [ starting, setStarting ] = useState( false );

	useEffect( () => {
		if ( overview.data ) {
			setActiveRun( overview.data.active_run );
		}
	}, [ overview.data ] );

	const onRunDone = useCallback(
		( run ) => {
			overview.reload();
			if ( run.status === 'done' ) {
				addNotice(
					'success',
					__( 'Speed test finished.', 'seo-console' )
				);
				return;
			}
			getRun( run.id ).then(
				( data ) => {
					const failed = ( data.results || [] ).filter(
						( r ) => r.status === 'failed'
					);
					addNotice(
						run.status === 'failed' ? 'error' : 'warning',
						[
							run.status === 'failed'
								? __(
										'The speed test could not test any page.',
										'seo-console'
								  )
								: __(
										'The speed test finished, but some pages could not be tested.',
										'seo-console'
								  ),
							...failed.map(
								( r ) =>
									`${ r.label || r.url } (${ r.strategy }): ${
										r.error || r.error_code
									}`
							),
						].join( ' ' )
					);
				},
				( error ) => addNotice( 'error', error.message )
			);
		},
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ addNotice, overview.reload ]
	);

	const run = () => {
		setStarting( true );
		startRun().then(
			( res ) => {
				setStarting( false );
				setActiveRun( res.run );
				addNotice(
					'info',
					__(
						'Speed test started. Results appear here in a few minutes.',
						'seo-console'
					)
				);
			},
			( error ) => {
				setStarting( false );
				addNotice( 'error', error.message );
				overview.reload();
			}
		);
	};

	const running =
		!! activeRun && [ 'queued', 'running' ].includes( activeRun.status );

	return (
		<Page
			title={ __( 'Speed', 'seo-console' ) }
			description={ __(
				'How fast your pages load for visitors and for Google, what to fix first, and safe tweaks you can undo.',
				'seo-console'
			) }
			actions={
				<Button
					variant="primary"
					isBusy={ starting || running }
					disabled={ starting || running }
					onClick={ run }
				>
					{ running
						? __( 'Test running…', 'seo-console' )
						: __( 'Run test now', 'seo-console' ) }
				</Button>
			}
			notices={ notices }
			onRemoveNotice={ removeNotice }
		>
			<TabPanelRoute tabs={ TABS }>
				{ ( tab ) => {
					switch ( tab ) {
						case 'todo':
							return <TodoPanel addNotice={ addNotice } />;
						case 'tweaks':
							return <TweaksPanel addNotice={ addNotice } />;
						case 'cache':
							return <CachePanel />;
						case 'database':
							return <DatabasePanel addNotice={ addNotice } />;
						case 'pages':
							return (
								<PagesPanel
									addNotice={ addNotice }
									onSaved={ overview.reload }
								/>
							);
						default:
							if ( overview.loading && ! overview.data ) {
								return <Loading />;
							}
							if ( overview.error ) {
								return (
									<ErrorNotice
										error={ overview.error }
										onRetry={ overview.reload }
									/>
								);
							}
							return (
								<OverviewTab
									overview={ overview.data }
									reload={ overview.reload }
									activeRun={ activeRun }
									onRunDone={ onRunDone }
									addNotice={ addNotice }
								/>
							);
					}
				} }
			</TabPanelRoute>
		</Page>
	);
}

mount( App );
