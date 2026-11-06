/**
 * Overview: Core Web Vitals from real users, lab scores per page, trends and run progress.
 */
import { Button, Notice } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import {
	Card,
	DataHonesty,
	EmptyState,
	MetricTile,
	SourceLine,
	StatusBadge,
	TrendChart,
	adminPageUrl,
	formatDate,
	formatRelative,
} from '../shared';
import { getRun, refreshCrux } from './api';
import {
	LIMITS,
	METRICS,
	formatCls,
	formatMetric,
	formatMs,
	rateValue,
	ratingToStatus,
	scoreStatus,
	strategyLabel,
} from './utils';

const ACTIVE = [ 'queued', 'running' ];

function RunProgress( { run, onDone } ) {
	const [ current, setCurrent ] = useState( run );
	useEffect( () => {
		setCurrent( run );
		if ( ! run || ! ACTIVE.includes( run.status ) ) {
			return undefined;
		}
		const timer = window.setInterval( () => {
			getRun( run.id ).then(
				( data ) => {
					setCurrent( data.run );
					if ( ! ACTIVE.includes( data.run.status ) ) {
						window.clearInterval( timer );
						onDone( data.run );
					}
				},
				() => {}
			);
		}, 5000 );
		return () => window.clearInterval( timer );
	}, [ run, onDone ] );

	if ( ! current || ! ACTIVE.includes( current.status ) ) {
		return null;
	}
	return (
		<Notice status="info" isDismissible={ false }>
			<p>
				<strong>{ __( 'Speed test running…', 'seo-console' ) }</strong>{ ' ' }
				{ sprintf(
					/* translators: 1: tests finished, 2: tests in total */
					__( '%1$d of %2$d page tests finished.', 'seo-console' ),
					current.urls_done,
					current.urls_total
				) }{ ' ' }
				{ __(
					'Each page takes up to a minute at Google and runs in the background: you can leave this page.',
					'seo-console'
				) }
			</p>
		</Notice>
	);
}

function FieldTiles( { record } ) {
	const metrics = ( record && record.metrics ) || {};
	return (
		<div className="seoc-grid">
			{ [ 'lcp', 'inp', 'cls' ].map( ( key ) => {
				const m = metrics[ key ];
				return (
					<MetricTile
						key={ key }
						label={ METRICS[ key ].label() }
						help={ METRICS[ key ].help() }
						value={ m ? m.p75 : null }
						format={ ( v ) => formatMetric( key, v ) }
						status={
							m
								? ratingToStatus( m.rating || m.category )
								: undefined
						}
						footnote={
							m
								? __(
										'75% of visits were this fast or faster',
										'seo-console'
								  )
								: __(
										'Not enough data for this metric',
										'seo-console'
								  )
						}
					/>
				);
			} ) }
		</div>
	);
}

function assessmentBadge( assessment ) {
	if ( assessment === 'pass' ) {
		return (
			<StatusBadge
				status="good"
				label={ __( 'Core Web Vitals: passed', 'seo-console' ) }
			/>
		);
	}
	if ( assessment === 'fail' ) {
		return (
			<StatusBadge
				status="poor"
				label={ __( 'Core Web Vitals: failed', 'seo-console' ) }
			/>
		);
	}
	return (
		<StatusBadge
			status="unknown"
			label={ __( 'Core Web Vitals: not enough data', 'seo-console' ) }
		/>
	);
}

function psiOriginAssessment( block ) {
	if ( ! block || ! block.metrics ) {
		return null;
	}
	if ( ! block.metrics.lcp || ! block.metrics.cls ) {
		return null;
	}
	const keys = [ 'lcp', 'inp', 'cls' ].filter( ( k ) => block.metrics[ k ] );
	return keys.every( ( k ) => block.metrics[ k ].category === 'good' )
		? 'pass'
		: 'fail';
}

function FieldCard( { overview, onRefreshed, addNotice } ) {
	const [ busy, setBusy ] = useState( false );
	const field = overview.field || {};
	const phone = field.phone && field.phone.data;
	const desktop = field.desktop && field.desktop.data;
	let record = null;
	let source = null;
	if ( phone && phone.has_data ) {
		record = phone;
		source = 'crux';
	} else if ( field.psi_origin ) {
		source = 'psi';
	}

	const refresh = () => {
		setBusy( true );
		refreshCrux().then(
			() => {
				setBusy( false );
				onRefreshed();
			},
			( error ) => {
				setBusy( false );
				addNotice( 'error', error.message );
			}
		);
	};

	return (
		<Card
			title={ __( 'Real visitors (Core Web Vitals)', 'seo-console' ) }
			description={ __(
				'What real Chrome users experienced on your site over the last 28 days, on phones. Google uses this for ranking.',
				'seo-console'
			) }
			actions={
				overview.has_key ? (
					<Button
						variant="secondary"
						isBusy={ busy }
						disabled={ busy }
						onClick={ refresh }
					>
						{ __( 'Update real-user data', 'seo-console' ) }
					</Button>
				) : null
			}
		>
			{ source === 'crux' && (
				<>
					<p>
						{ assessmentBadge( record.assessment ) }{ ' ' }
						<DataHonesty
							kind="field"
							asOf={ field.phone.fetched_at }
							detail={
								record.period
									? sprintf(
											/* translators: 1: start date, 2: end date */
											__(
												'Collection period %1$s – %2$s.',
												'seo-console'
											),
											record.period.first,
											record.period.last
									  )
									: ''
							}
						/>
					</p>
					<FieldTiles record={ record } />
					{ desktop && desktop.has_data && (
						<SourceLine>
							{ sprintf(
								/* translators: 1: pass/fail words, 2: LCP, 3: CLS */
								__(
									'Desktop visitors: %1$s (LCP %2$s, CLS %3$s).',
									'seo-console'
								),
								desktop.assessment === 'pass'
									? __( 'passed', 'seo-console' )
									: __( 'failed', 'seo-console' ),
								desktop.metrics.lcp
									? formatMs( desktop.metrics.lcp.p75 )
									: '—',
								desktop.metrics.cls
									? formatCls( desktop.metrics.cls.p75 )
									: '—'
							) }
						</SourceLine>
					) }
				</>
			) }
			{ source === 'psi' && (
				<>
					<p>
						{ assessmentBadge(
							psiOriginAssessment( field.psi_origin )
						) }{ ' ' }
						<DataHonesty
							kind="field"
							detail={ __(
								'Taken from the last PageSpeed Insights test of the home page (whole site, phones).',
								'seo-console'
							) }
						/>
					</p>
					<FieldTiles record={ field.psi_origin } />
				</>
			) }
			{ ! source && (
				<EmptyState
					icon="groups"
					title={ __( 'No real-user data yet', 'seo-console' ) }
				>
					{ overview.has_key
						? __(
								'Google only publishes real-user data when enough Chrome visitors visit the site. Small or new sites often have none: use the lab tests below instead.',
								'seo-console'
						  )
						: __(
								'Real-user data comes from the Chrome UX Report and needs a Google API key. Lab tests below work without one (with a small shared quota).',
								'seo-console'
						  ) }
				</EmptyState>
			) }
		</Card>
	);
}

function LabTable( { overview } ) {
	const pages = overview.pages || [];
	const strategies = overview.strategies || [ 'mobile', 'desktop' ];
	const limits = overview.thresholds || LIMITS;
	const anyResult = pages.some( ( p ) => p.mobile || p.desktop );
	return (
		<Card
			title={ __( 'Lab tests per page', 'seo-console' ) }
			description={ __(
				'One simulated visit per page by Google PageSpeed Insights. Good for finding problems; scores vary a few points between runs.',
				'seo-console'
			) }
			actions={ <DataHonesty kind="lab" /> }
		>
			{ ! anyResult ? (
				<EmptyState
					icon="performance"
					title={ __( 'No speed test yet', 'seo-console' ) }
				>
					{ __(
						'Click "Run test now" to test your most important pages. It takes a few minutes and runs in the background.',
						'seo-console'
					) }
				</EmptyState>
			) : (
				<div className="seoc-table-scroll">
					<table className="widefat striped seoc-table">
						<thead>
							<tr>
								<th scope="col">
									{ __( 'Page', 'seo-console' ) }
								</th>
								<th scope="col">
									{ __( 'Device', 'seo-console' ) }
								</th>
								<th scope="col">
									{ __( 'Score', 'seo-console' ) }
								</th>
								<th scope="col">LCP</th>
								<th scope="col">CLS</th>
								<th scope="col">TBT</th>
								<th scope="col">
									{ __( 'Tested', 'seo-console' ) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ pages.map( ( page ) =>
								strategies.map( ( s, i ) => {
									const r = page[ s ];
									return (
										<tr key={ `${ page.url }-${ s }` }>
											{ i === 0 && (
												<th
													scope="row"
													rowSpan={
														strategies.length
													}
												>
													<a
														href={ page.url }
														target="_blank"
														rel="noreferrer"
													>
														{ page.label ||
															page.url }
													</a>
												</th>
											) }
											<td>{ strategyLabel( s ) }</td>
											<td>
												{ r ? (
													<StatusBadge
														status={ scoreStatus(
															r.score
														) }
														label={ `${ r.score } / 100` }
													/>
												) : (
													<span className="seoc-muted">
														{ __(
															'Not tested',
															'seo-console'
														) }
													</span>
												) }
											</td>
											{ [ 'lcp', 'cls', 'tbt' ].map(
												( m ) => {
													const value = r
														? r[
																m === 'cls'
																	? 'cls'
																	: `${ m }_ms`
														  ]
														: null;
													return (
														<td
															key={ m }
															className="seoc-num"
														>
															{ r ? (
																<StatusBadge
																	status={ rateValue(
																		m,
																		value,
																		limits
																	) }
																	label={ formatMetric(
																		m,
																		value
																	) }
																/>
															) : (
																'—'
															) }
														</td>
													);
												}
											) }
											<td>
												{ r
													? formatRelative(
															r.fetched_at
													  )
													: '—' }
											</td>
										</tr>
									);
								} )
							) }
						</tbody>
					</table>
				</div>
			) }
		</Card>
	);
}

function seriesFrom( list, key, scale = 1 ) {
	return ( list || [] ).map( ( p ) => ( {
		date: p.date,
		value:
			p[ key ] === null || p[ key ] === undefined
				? null
				: p[ key ] * scale,
	} ) );
}

function historySeries( history, metric, scale = 1 ) {
	const data = history && history.data;
	if ( ! data || ! data.has_data || ! data.metrics[ metric ] ) {
		return [];
	}
	return data.periods.map( ( date, i ) => {
		const v = data.metrics[ metric ].p75[ i ];
		return {
			date,
			value: v === null || v === undefined ? null : v * scale,
		};
	} );
}

function band( metric, limits, scale = 1, top = null ) {
	const l = limits[ metric ];
	const max = top === null ? l[ 1 ] * 1.6 * scale : top;
	return [
		{
			from: 0,
			to: l[ 0 ] * scale,
			tone: 'good',
			label: __( 'Good', 'seo-console' ),
		},
		{
			from: l[ 0 ] * scale,
			to: l[ 1 ] * scale,
			tone: 'needs-work',
			label: __( 'Needs improvement', 'seo-console' ),
		},
		{
			from: l[ 1 ] * scale,
			to: max,
			tone: 'poor',
			label: __( 'Poor', 'seo-console' ),
		},
	];
}

function Trends( { overview } ) {
	const trend = overview.trend || {};
	const limits = overview.thresholds || LIMITS;
	const markers = ( trend.markers || [] ).map( ( m ) => ( {
		date: m.date,
		label: m.label,
	} ) );
	return (
		<div className="seoc-grid seoc-grid--2">
			<Card title={ __( 'Lab score over time', 'seo-console' ) }>
				<TrendChart
					title={ __( 'PageSpeed score over time', 'seo-console' ) }
					description={ __(
						'Average score of the tested pages for each test run (0–100, higher is better). Dots on the top line mark re-tests after a change.',
						'seo-console'
					) }
					series={ [
						{
							id: 'mobile',
							label: __( 'Mobile', 'seo-console' ),
							points: seriesFrom( trend.mobile, 'score' ),
						},
						{
							id: 'desktop',
							label: __( 'Desktop', 'seo-console' ),
							points: seriesFrom( trend.desktop, 'score' ),
						},
					] }
					bands={ [
						{
							from: 90,
							to: 100,
							tone: 'good',
							label: __( 'Good (90+)', 'seo-console' ),
						},
						{
							from: 50,
							to: 90,
							tone: 'needs-work',
							label: __( 'Needs work (50–89)', 'seo-console' ),
						},
						{
							from: 0,
							to: 50,
							tone: 'poor',
							label: __( 'Poor (0–49)', 'seo-console' ),
						},
					] }
					markers={ markers }
					yMin={ 0 }
					yMax={ 100 }
					footnote={ __(
						'Source: PageSpeed Insights (lab).',
						'seo-console'
					) }
				/>
			</Card>
			<Card title={ __( 'Lab LCP over time (mobile)', 'seo-console' ) }>
				<TrendChart
					title={ __(
						'Largest Contentful Paint, lab, mobile',
						'seo-console'
					) }
					description={ __(
						'Average LCP of the tested pages in each run, in seconds (lower is better).',
						'seo-console'
					) }
					series={ [
						{
							id: 'lcp',
							label: 'LCP',
							points: seriesFrom( trend.mobile, 'lcp_ms', 0.001 ),
						},
					] }
					bands={ band( 'lcp', limits, 0.001 ) }
					markers={ markers }
					yMin={ 0 }
					unit="s"
					footnote={ __(
						'Source: PageSpeed Insights (lab).',
						'seo-console'
					) }
				/>
			</Card>
			{ [
				[ 'lcp', 0.001, 's' ],
				[ 'inp', 1, 'ms' ],
				[ 'cls', 1, '' ],
			].map( ( [ metric, scale, unit ] ) => (
				<Card
					key={ metric }
					title={ sprintf(
						/* translators: %s: metric name such as LCP */
						__( 'Real users: %s over time (phone)', 'seo-console' ),
						METRICS[ metric ].short
					) }
				>
					<TrendChart
						title={ sprintf(
							/* translators: %s: metric name */
							__(
								'%s, real users, phone, 75th percentile',
								'seo-console'
							),
							METRICS[ metric ].label()
						) }
						description={ __(
							'Each point covers the 28 days before that date (Chrome UX Report history). Lower is better.',
							'seo-console'
						) }
						series={ [
							{
								id: metric,
								label: METRICS[ metric ].short,
								points: historySeries(
									trend.field_phone,
									metric,
									scale
								),
							},
						] }
						bands={ band( metric, limits, scale ) }
						yMin={ 0 }
						unit={ unit }
						emptyMessage={
							overview.has_key
								? __(
										'Google has no real-user history for this site yet (not enough Chrome visitors), or it has not been fetched yet.',
										'seo-console'
								  )
								: __(
										'Real-user history needs a Google API key (Settings).',
										'seo-console'
								  )
						}
						footnote={ __(
							'Source: Chrome UX Report (field).',
							'seo-console'
						) }
					/>
				</Card>
			) ) }
		</div>
	);
}

export default function OverviewTab( {
	overview,
	reload,
	activeRun,
	onRunDone,
	addNotice,
} ) {
	const scheduleText = {
		daily: __( 'Automatic tests run every night.', 'seo-console' ),
		weekly: __( 'Automatic tests run once a week.', 'seo-console' ),
		off: __( 'Automatic tests are off.', 'seo-console' ),
	};
	const pagesCount = ( overview.pages || [] ).length;
	return (
		<>
			{ ! overview.has_key && (
				<Notice status="info" isDismissible={ false }>
					<p>
						{ __(
							'Tip: add a free Google API key to get real-user data (Core Web Vitals) and a much higher PageSpeed quota. Without a key, lab tests still work.',
							'seo-console'
						) }{ ' ' }
						<a href={ adminPageUrl( 'settings' ) }>
							{ __( 'Add the key in Settings', 'seo-console' ) }
						</a>
					</p>
				</Notice>
			) }
			<RunProgress run={ activeRun } onDone={ onRunDone } />
			<FieldCard
				overview={ overview }
				onRefreshed={ reload }
				addNotice={ addNotice }
			/>
			<LabTable overview={ overview } />
			<Trends overview={ overview } />
			<SourceLine>
				{ [
					scheduleText[ overview.schedule ] || '',
					overview.next_run
						? sprintf(
								/* translators: %s: date and time */
								__( 'Next automatic test: %s.', 'seo-console' ),
								formatDate( overview.next_run )
						  )
						: '',
					overview.last_run
						? sprintf(
								/* translators: %s: relative time */
								__( 'Last finished test: %s.', 'seo-console' ),
								formatRelative( overview.last_run.finished_at )
						  )
						: '',
					sprintf(
						/* translators: %d: number of pages */
						_n(
							'%d page is tested.',
							'%d pages are tested.',
							pagesCount,
							'seo-console'
						),
						pagesCount
					),
				]
					.filter( Boolean )
					.join( ' ' ) }
			</SourceLine>
		</>
	);
}
