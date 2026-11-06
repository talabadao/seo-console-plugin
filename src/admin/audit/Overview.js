/**
 * Overview tab: score, trend, counts, top issues, new vs fixed, "Fix with AI".
 */
import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import {
	Card,
	MetricTile,
	StatusBadge,
	TrendChart,
	adminPageUrl,
	formatNumber,
	getConfig,
	isModuleEnabled,
	useAsync,
} from '../shared';
import { getRules, requestAiFixes } from './api';
import {
	SEVERITY_STATUS,
	categoryLabel,
	fixableLabel,
	scoreStatus,
	severityLabel,
} from './labels';

const SEVERITIES = [ 'error', 'warning', 'notice' ];

const PLURAL = {
	error: () => __( 'Errors', 'seo-console' ),
	warning: () => __( 'Warnings', 'seo-console' ),
	notice: () => __( 'Notices', 'seo-console' ),
};

function AiCard( { runId, summary, onNotice } ) {
	const [ busy, setBusy ] = useState( false );
	const byRule = ( summary && summary.by_rule ) || {};
	const aiRules = Object.keys( byRule ).filter(
		( id ) => byRule[ id ].fixable_by === 'ai'
	);
	const count = aiRules.reduce( ( n, id ) => n + byRule[ id ].count, 0 );
	if ( ! count ) {
		return null;
	}
	const config = getConfig();
	const metaOn = isModuleEnabled( 'meta' );

	const run = async () => {
		setBusy( true );
		try {
			const result = await requestAiFixes( runId );
			onNotice(
				'success',
				result.queued
					? sprintf(
							/* translators: %s: number of pages */
							_n(
								'%s page was sent for AI suggestions. They appear in Suggestions for you to review; nothing changes until you approve.',
								'%s pages were sent for AI suggestions. They appear in Suggestions for you to review; nothing changes until you approve.',
								result.queued,
								'seo-console'
							),
							formatNumber( result.queued )
					  )
					: __(
							'None of these issues are on posts or pages the AI can edit (for example archives).',
							'seo-console'
					  ),
				result.queued
					? {
							actions: [
								{
									label: __(
										'Open Suggestions',
										'seo-console'
									),
									url: result.suggestions_url,
								},
							],
					  }
					: {}
			);
		} catch ( error ) {
			onNotice( 'error', error.message );
		}
		setBusy( false );
	};

	return (
		<Card
			title={ __( 'Fix with AI', 'seo-console' ) }
			tone="attention"
			description={ sprintf(
				/* translators: %s: number of issues */
				_n(
					'%s issue with titles, descriptions or image alt text can get an AI suggestion that you review and approve.',
					'%s issues with titles, descriptions or image alt text can get an AI suggestion that you review and approve.',
					count,
					'seo-console'
				),
				formatNumber( count )
			) }
		>
			{ ! metaOn && (
				<p>
					{ __( 'Turn on the AI meta module first.', 'seo-console' ) }{ ' ' }
					<a href={ adminPageUrl( 'settings', { tab: 'modules' } ) }>
						{ __( 'Open modules', 'seo-console' ) }
					</a>
				</p>
			) }
			{ metaOn && ! config.aiConfigured && (
				<p>
					{ __( 'Add your Claude API key first.', 'seo-console' ) }{ ' ' }
					<a href={ adminPageUrl( 'settings', { tab: 'keys' } ) }>
						{ __( 'Add the key', 'seo-console' ) }
					</a>
				</p>
			) }
			<Button
				variant="primary"
				onClick={ run }
				isBusy={ busy }
				disabled={ busy || ! metaOn }
			>
				{ __( 'Get AI suggestions', 'seo-console' ) }
			</Button>
		</Card>
	);
}

function DiffCard( { diff } ) {
	if ( ! diff ) {
		return null;
	}
	const row = ( label, part, status ) => (
		<li>
			<StatusBadge
				status={ status }
				label={ formatNumber( part.count ) }
			/>{ ' ' }
			{ label }
		</li>
	);
	return (
		<Card
			title={ __( 'Since the previous audit', 'seo-console' ) }
			headingLevel={ 3 }
		>
			<ul className="seoc-plain-list">
				{ row(
					__( 'new issues', 'seo-console' ),
					diff.new,
					diff.new.count ? 'poor' : 'good'
				) }
				{ row(
					__( 'issues fixed', 'seo-console' ),
					diff.fixed,
					diff.fixed.count ? 'good' : 'neutral'
				) }
				{ row(
					__( 'still open', 'seo-console' ),
					diff.open,
					'neutral'
				) }
			</ul>
			{ diff.new.count > 0 && (
				<details>
					<summary>{ __( 'New issues', 'seo-console' ) }</summary>
					<ul className="seoc-plain-list seoc-small">
						{ diff.new.items.slice( 0, 50 ).map( ( item, i ) => (
							<li key={ i }>
								<code>{ item.rule_id }</code>{ ' ' }
								{ item.url ||
									__( '(whole site)', 'seo-console' ) }
							</li>
						) ) }
					</ul>
				</details>
			) }
		</Card>
	);
}

/**
 * @param {Object}   props
 * @param {Object}   props.detail   Run payload ({ run, progress, summary, diff }).
 * @param {Array}    props.runs     Run history.
 * @param {Function} props.onNotice addNotice.
 * @param {Function} props.openTab  Open a tab with query args.
 * @return {JSX.Element} Tab.
 */
export default function Overview( { detail, runs, onNotice, openTab } ) {
	const rules = useAsync( ( signal ) => getRules( signal ), [] );
	const { run, summary, diff } = detail;
	const meta = rules.data ? rules.data.rules : {};
	const severity = ( summary && summary.by_severity ) || {};
	const history = runs
		.filter( ( r ) => r.status === 'done' && r.score !== null )
		.slice()
		.reverse();

	return (
		<div className="seoc-audit-overview">
			<div className="seoc-grid">
				<MetricTile
					label={ __( 'Health score', 'seo-console' ) }
					value={ run.score }
					unit="/100"
					status={ scoreStatus( run.score ) }
					help={ __(
						'100 means no issues. Errors weigh most, notices least; problems on many pages lower it more.',
						'seo-console'
					) }
					delta={
						diff && diff.score_change !== null
							? {
									value: diff.score_change,
									betterWhen: 'up',
									label: __(
										'vs previous audit',
										'seo-console'
									),
							  }
							: undefined
					}
				/>
				{ SEVERITIES.map( ( s ) => (
					<MetricTile
						key={ s }
						label={ PLURAL[ s ]() }
						value={
							severity[ s ] === undefined ? null : severity[ s ]
						}
						status={ severity[ s ] ? SEVERITY_STATUS[ s ] : 'good' }
						footnote={
							<Button
								variant="link"
								onClick={ () =>
									openTab( 'issues', { severity: s } )
								}
							>
								{ __( 'See them', 'seo-console' ) }
							</Button>
						}
					/>
				) ) }
				<MetricTile
					label={ __( 'Pages checked', 'seo-console' ) }
					value={ run.pages_crawled }
					footnote={ sprintf(
						/* translators: %s: number of addresses */
						__( '%s addresses found', 'seo-console' ),
						formatNumber( run.pages_found )
					) }
				/>
			</div>

			<AiCard
				runId={ run.id }
				summary={ summary }
				onNotice={ onNotice }
			/>

			{ history.length > 1 && (
				<Card title={ __( 'Health score over time', 'seo-console' ) }>
					<TrendChart
						title={ __( 'Health score', 'seo-console' ) }
						description={ __(
							'Score of each finished audit (0–100, higher is better).',
							'seo-console'
						) }
						series={ [
							{
								id: 'score',
								label: __( 'Health score', 'seo-console' ),
								points: history.map( ( r ) => ( {
									date: r.finished_at,
									value: r.score,
								} ) ),
							},
						] }
						yMin={ 0 }
						yMax={ 100 }
						thresholds={ [
							{
								value: 80,
								label: __( 'Good', 'seo-console' ),
								tone: 'good',
							},
						] }
					/>
				</Card>
			) }

			<div className="seoc-grid seoc-grid--2">
				<Card
					title={ __( 'Top issues', 'seo-console' ) }
					headingLevel={ 3 }
				>
					{ summary &&
					summary.top_issues &&
					summary.top_issues.length ? (
						<ol className="seoc-audit-top">
							{ summary.top_issues.map( ( item ) => {
								const m = meta[ item.rule_id ] || {};
								return (
									<li key={ item.rule_id }>
										<StatusBadge
											status={
												SEVERITY_STATUS[ item.severity ]
											}
											label={ severityLabel(
												item.severity
											) }
										/>{ ' ' }
										<Button
											variant="link"
											onClick={ () =>
												openTab( 'issues', {
													rule: item.rule_id,
												} )
											}
										>
											{ item.title }
										</Button>{ ' ' }
										<span className="seoc-muted">
											({ formatNumber( item.count ) })
										</span>
										{ m.why && (
											<div className="seoc-small">
												<div>{ m.why }</div>
												<div>
													<strong>
														{ __(
															'How to fix:',
															'seo-console'
														) }
													</strong>{ ' ' }
													{ m.how_to_fix }{ ' ' }
													<span className="seoc-muted">
														(
														{ fixableLabel(
															m.fixable_by
														) }
														)
													</span>
												</div>
											</div>
										) }
									</li>
								);
							} ) }
						</ol>
					) : (
						<p>
							<StatusBadge
								status="good"
								label={ __( 'No issues', 'seo-console' ) }
							/>
						</p>
					) }
				</Card>
				<div>
					<DiffCard diff={ diff } />
					<Card
						title={ __( 'Issues by area', 'seo-console' ) }
						headingLevel={ 3 }
						flush
					>
						<table className="widefat striped seoc-table">
							<thead>
								<tr>
									<th scope="col">
										{ __( 'Area', 'seo-console' ) }
									</th>
									{ SEVERITIES.map( ( s ) => (
										<th scope="col" key={ s }>
											{ severityLabel( s ) }
										</th>
									) ) }
								</tr>
							</thead>
							<tbody>
								{ Object.keys(
									( summary && summary.by_category ) || {}
								).map( ( c ) => (
									<tr key={ c }>
										<th scope="row">
											<Button
												variant="link"
												onClick={ () =>
													openTab( 'issues', {
														category: c,
													} )
												}
											>
												{ categoryLabel( c ) }
											</Button>
										</th>
										{ SEVERITIES.map( ( s ) => (
											<td className="seoc-num" key={ s }>
												{ formatNumber(
													summary.by_category[ c ][
														s
													]
												) }
											</td>
										) ) }
									</tr>
								) ) }
							</tbody>
						</table>
					</Card>
				</div>
			</div>
		</div>
	);
}
