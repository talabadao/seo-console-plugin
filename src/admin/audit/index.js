/**
 * Site audit page: run / cancel with live progress, then Overview, Issues, Pages, Robots.txt and Sitemaps tabs.
 */
import { Button, SelectControl } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	Card,
	ConfirmButton,
	EmptyState,
	ErrorNotice,
	LiveRegion,
	Loading,
	Page,
	StatusBadge,
	TabPanelRoute,
	formatDate,
	formatRelative,
	getUrlParams,
	mount,
	setUrlParams,
	useAsync,
	useNotices,
} from '../shared';
import { advanceRun, cancelRun, getRun, getRuns, startRun } from './api';
import IssuesTab from './IssuesTab';
import Overview from './Overview';
import PagesTab from './PagesTab';
import RobotsTab from './RobotsTab';
import SitemapsTab from './SitemapsTab';
import {
	phaseSentence,
	progressShare,
	runStatusLabel,
	scoreStatus,
} from './labels';
import './audit.scss';

const POLL_MS = 3000;

function ProgressCard( { detail } ) {
	const share = progressShare( detail.progress );
	const sentence = phaseSentence( detail.progress );
	return (
		<Card
			title={ __( 'Audit in progress', 'seo-console' ) }
			description={ __(
				'You can leave this page: the audit continues in the background, in small steps so your site stays fast.',
				'seo-console'
			) }
		>
			<p>{ sentence }</p>
			<div
				className="seoc-meter"
				role="progressbar"
				aria-valuemin={ 0 }
				aria-valuemax={ 100 }
				aria-valuenow={ Math.round( share * 100 ) }
				aria-label={ __( 'Audit progress', 'seo-console' ) }
			>
				<span
					className="seoc-meter__fill"
					style={ { width: `${ Math.round( share * 100 ) }%` } }
				/>
			</div>
			<LiveRegion visible={ false }>{ sentence }</LiveRegion>
		</Card>
	);
}

function LoopbackCard( { run } ) {
	return (
		<Card
			title={ __( 'The audit could not reach your site', 'seo-console' ) }
			tone="attention"
		>
			<p>
				{ __(
					'To audit your pages, WordPress needs to open your own website from the server (a "loopback" request). Your hosting blocked or failed that request, so no page could be checked.',
					'seo-console'
				) }
			</p>
			<p>
				<strong>
					{ __(
						'What to send your hosting provider:',
						'seo-console'
					) }
				</strong>
			</p>
			<blockquote className="seoc-audit-quote">
				{ sprintf(
					/* translators: %s: site address */
					__(
						'Hello, my WordPress site %s cannot make HTTP requests to its own address (loopback requests fail). Please allow the server to connect to its own domain (check the firewall, DNS / hosts file and SSL certificate). Thank you!',
						'seo-console'
					),
					window.location.origin
				) }
			</blockquote>
			<p className="seoc-muted seoc-small">
				{ __(
					'WordPress Tools → Site Health usually shows the same problem. When it is fixed, run the audit again.',
					'seo-console'
				) }{ ' ' }
				{ run.error && <code>{ run.error }</code> }
			</p>
		</Card>
	);
}

function App() {
	const { notices, addNotice, removeNotice } = useNotices();
	const runs = useAsync(
		( signal ) => getRuns( { per_page: 20 }, signal ),
		[]
	);
	const [ selected, setSelected ] = useState(
		parseInt( getUrlParams().get( 'run' ), 10 ) || 0
	);
	const [ starting, setStarting ] = useState( false );

	const runList = runs.data ? runs.data.items : [];
	let runId = selected;
	if ( ! runId && runs.data ) {
		runId =
			runs.data.active_run_id || ( runList[ 0 ] ? runList[ 0 ].id : 0 );
	}

	const detail = useAsync(
		( signal ) => ( runId ? getRun( runId, signal ) : null ),
		[ runId ]
	);
	const running = !! (
		detail.data &&
		detail.data.progress &&
		! detail.data.progress.done
	);

	// Poll while running: each poll also does a few seconds of work, because background jobs may only run
	// when someone visits the site.
	const reloadRuns = useRef( runs.reload );
	reloadRuns.current = runs.reload;
	const setDetail = useRef( detail.setData );
	setDetail.current = detail.setData;
	useEffect( () => {
		if ( ! running || ! runId ) {
			return undefined;
		}
		let alive = true;
		const timer = setTimeout( async () => {
			try {
				const next = await advanceRun( runId );
				if ( alive ) {
					setDetail.current( next );
					if ( next.progress.done ) {
						reloadRuns.current();
						setDetail.current( await getRun( runId ) );
					}
				}
			} catch ( e ) {
				if ( alive ) {
					// Keep the last state; try again on the next tick.
					setDetail.current( ( d ) => ( { ...d } ) );
				}
			}
		}, POLL_MS );
		return () => {
			alive = false;
			clearTimeout( timer );
		};
	}, [ running, runId, detail.data ] );

	const start = async () => {
		setStarting( true );
		try {
			const payload = await startRun();
			setSelected( payload.run.id );
			setUrlParams( { run: '' } );
			detail.setData( payload );
			runs.reload();
		} catch ( error ) {
			addNotice( 'error', error.message );
			if ( error.data && error.data.run_id ) {
				setSelected( error.data.run_id );
			}
		}
		setStarting( false );
	};

	const cancel = async () => {
		try {
			detail.setData( await cancelRun( runId ) );
			runs.reload();
		} catch ( error ) {
			addNotice( 'error', error.message );
		}
	};

	// Switch tab with filters: update the URL, then let TabPanelRoute re-read it (it listens to popstate).
	const openTab = ( tab, args = {} ) => {
		setUrlParams( {
			tab,
			rule: '',
			severity: '',
			category: '',
			...args,
		} );
		window.dispatchEvent( new window.PopStateEvent( 'popstate' ) );
	};

	const run = detail.data ? detail.data.run : null;
	const actions = running ? (
		<ConfirmButton
			message={ __( 'Stop this audit?', 'seo-console' ) }
			onConfirm={ cancel }
			isDestructive
		>
			{ __( 'Cancel audit', 'seo-console' ) }
		</ConfirmButton>
	) : (
		<Button
			variant="primary"
			onClick={ start }
			isBusy={ starting }
			disabled={ starting || runs.loading }
		>
			{ starting
				? __( 'Starting…', 'seo-console' )
				: __( 'Run audit now', 'seo-console' ) }
		</Button>
	);

	let meta = null;
	if ( run && run.finished_at ) {
		meta = sprintf(
			/* translators: 1: relative time, 2: date */
			__( 'Last audit finished %1$s (%2$s).', 'seo-console' ),
			formatRelative( run.finished_at ),
			formatDate( run.finished_at )
		);
	}

	const finishedRuns = runList.filter( ( r ) => r.status !== 'running' );

	return (
		<Page
			title={ __( 'Site audit', 'seo-console' ) }
			description={ __(
				'Finds broken links, indexing mistakes, missing titles and robots.txt or sitemap problems by visiting your pages like a search engine.',
				'seo-console'
			) }
			meta={ meta }
			actions={ actions }
			notices={ notices }
			onRemoveNotice={ removeNotice }
		>
			{ runs.error && (
				<ErrorNotice error={ runs.error } onRetry={ runs.reload } />
			) }
			{ runs.loading && ! runs.data && <Loading /> }

			{ runs.data && ! runList.length && (
				<Card>
					<EmptyState
						title={ __( 'No audit yet', 'seo-console' ) }
						icon="search"
						actions={ actions }
					>
						{ __(
							'The first audit takes a few minutes. It visits up to 500 pages, gently, in the background.',
							'seo-console'
						) }
					</EmptyState>
				</Card>
			) }

			{ finishedRuns.length > 1 && (
				<div className="seoc-toolbar">
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Show audit', 'seo-console' ) }
						value={ String( runId ) }
						options={ runList.map( ( r ) => ( {
							value: String( r.id ),
							label: `${ formatDate(
								r.created_at
							) } — ${ runStatusLabel( r.status ) }${
								r.score !== null ? ` — ${ r.score }/100` : ''
							}`,
						} ) ) }
						onChange={ ( v ) => {
							setSelected( parseInt( v, 10 ) );
							setUrlParams( { run: v } );
						} }
					/>
				</div>
			) }

			{ detail.error && (
				<ErrorNotice error={ detail.error } onRetry={ detail.reload } />
			) }

			{ run && running && <ProgressCard detail={ detail.data } /> }
			{ run && run.error === 'loopback_failed' && (
				<LoopbackCard run={ run } />
			) }
			{ run && run.status === 'cancelled' && (
				<p>
					<StatusBadge
						status="neutral"
						label={ runStatusLabel( 'cancelled' ) }
					/>{ ' ' }
					{ __(
						'This audit was stopped before it finished.',
						'seo-console'
					) }
				</p>
			) }
			{ run && run.error === 'stalled' && (
				<p>
					<StatusBadge
						status="poor"
						label={ runStatusLabel( 'failed' ) }
					/>{ ' ' }
					{ __(
						'This audit stopped making progress (background tasks may not be running on your hosting). Run it again from this page.',
						'seo-console'
					) }
				</p>
			) }

			{ runList.length > 0 && (
				<TabPanelRoute
					tabs={ [
						{
							name: 'overview',
							title: __( 'Overview', 'seo-console' ),
						},
						{
							name: 'issues',
							title: __( 'Issues', 'seo-console' ),
						},
						{ name: 'pages', title: __( 'Pages', 'seo-console' ) },
						{
							name: 'robots',
							title: __( 'Robots.txt', 'seo-console' ),
						},
						{
							name: 'sitemaps',
							title: __( 'Sitemaps', 'seo-console' ),
						},
					] }
				>
					{ ( tab ) => {
						if ( tab === 'robots' ) {
							return <RobotsTab />;
						}
						if ( tab === 'sitemaps' ) {
							return <SitemapsTab />;
						}
						if ( ! run ) {
							return <Loading />;
						}
						if ( run.status !== 'done' ) {
							return (
								<EmptyState
									title={ __(
										'Results appear when the audit finishes',
										'seo-console'
									) }
								>
									{ running
										? phaseSentence( detail.data.progress )
										: __(
												'This audit did not finish. Pick another audit above or run a new one.',
												'seo-console'
										  ) }
								</EmptyState>
							);
						}
						if ( tab === 'issues' ) {
							return <IssuesTab runId={ run.id } />;
						}
						if ( tab === 'pages' ) {
							return <PagesTab runId={ run.id } />;
						}
						return (
							<>
								<p>
									<StatusBadge
										status={ scoreStatus( run.score ) }
										label={ runStatusLabel( run.status ) }
									/>
								</p>
								<Overview
									detail={ detail.data }
									runs={ runList }
									onNotice={ addNotice }
									openTab={ openTab }
								/>
							</>
						);
					} }
				</TabPanelRoute>
			) }
		</Page>
	);
}

mount( App );
