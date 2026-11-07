/**
 * Database health and one-off cleanups (permanent: always confirmed).
 */
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import {
	Card,
	ConfirmButton,
	ErrorNotice,
	Loading,
	MetricTile,
	StatusBadge,
	formatNumber,
} from '../shared';
import { runCleanup } from './api';

function formatBytes( bytes ) {
	if ( bytes >= 1024 * 1024 ) {
		return `${ formatNumber( bytes / ( 1024 * 1024 ), {
			maximumFractionDigits: 1,
		} ) } MB`;
	}
	return `${ formatNumber( Math.round( bytes / 1024 ) ) } KB`;
}

function Cleanup( { action, label, count, busy, onRun } ) {
	return (
		<ConfirmButton
			isDestructive
			disabled={ ! count }
			isBusy={ busy === action }
			message={ __(
				'This permanently deletes the data and cannot be undone. Continue?',
				'seo-console'
			) }
			confirmLabel={ __( 'Yes, delete', 'seo-console' ) }
			onConfirm={ () => onRun( action ) }
		>
			{ label }
		</ConfirmButton>
	);
}

export default function DatabaseTab( { maintenance, addNotice } ) {
	const [ busy, setBusy ] = useState( null );
	if ( maintenance.loading && ! maintenance.data ) {
		return <Loading />;
	}
	if ( maintenance.error ) {
		return (
			<ErrorNotice
				error={ maintenance.error }
				onRetry={ maintenance.reload }
			/>
		);
	}
	const r = maintenance.data;
	const as = r.action_scheduler;

	const run = ( action ) => {
		setBusy( action );
		return runCleanup( action ).then(
			( res ) => {
				setBusy( null );
				maintenance.setData( res.report );
				addNotice(
					'success',
					sprintf(
						/* translators: %d: number of rows */
						_n(
							'Removed %d item.',
							'Removed %d items.',
							res.result.removed,
							'seo-console'
						),
						res.result.removed
					) +
						( res.result.more
							? ' ' +
							  __(
									'There is more: click again to continue.',
									'seo-console'
							  )
							: '' )
				);
			},
			( error ) => {
				setBusy( null );
				addNotice( 'error', error.message );
			}
		);
	};

	return (
		<>
			<Card
				title={ __( 'Autoloaded options', 'seo-console' ) }
				description={ __(
					'Settings WordPress loads on every page view. A large total slows down every uncached page.',
					'seo-console'
				) }
			>
				<div className="seoc-grid">
					<MetricTile
						label={ __( 'Total size', 'seo-console' ) }
						value={ r.autoload.total_bytes }
						format={ formatBytes }
						status={ r.autoload.verdict }
					/>
					<MetricTile
						label={ __( 'Number of options', 'seo-console' ) }
						value={ r.autoload.count }
					/>
				</div>
				<p>{ r.autoload.advice }</p>
				<details>
					<summary>
						{ __( 'Show the biggest options', 'seo-console' ) }
					</summary>
					<div className="seoc-table-scroll">
						<table className="widefat striped seoc-table">
							<thead>
								<tr>
									<th scope="col">
										{ __( 'Option', 'seo-console' ) }
									</th>
									<th scope="col" className="seoc-num">
										{ __( 'Size', 'seo-console' ) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ r.autoload.top.map( ( o ) => (
									<tr key={ o.name }>
										<td>
											<code>{ o.name }</code>
											{ o.is_transient && (
												<span className="seoc-tag">
													{ __(
														'temporary',
														'seo-console'
													) }
												</span>
											) }
										</td>
										<td className="seoc-num">
											{ formatBytes( o.bytes ) }
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				</details>
			</Card>

			<div className="seoc-grid seoc-grid--2">
				<Card
					title={ __( 'Expired temporary data', 'seo-console' ) }
					headingLevel={ 3 }
				>
					<MetricTile
						label={ __( 'Expired transients', 'seo-console' ) }
						value={ r.transients.expired }
						status={ r.transients.verdict }
					/>
					<p className="seoc-muted">
						{ __(
							'Leftover cache entries that have expired. Safe to delete.',
							'seo-console'
						) }
					</p>
					<Cleanup
						action="expired_transients"
						label={ __(
							'Delete expired transients',
							'seo-console'
						) }
						count={ r.transients.expired }
						busy={ busy }
						onRun={ run }
					/>
				</Card>

				<Card
					title={ __( 'Background job history', 'seo-console' ) }
					headingLevel={ 3 }
				>
					{ as.available ? (
						<>
							<MetricTile
								label={ sprintf(
									/* translators: %d: days */
									__(
										'Finished jobs older than %d days',
										'seo-console'
									),
									as.days
								) }
								value={ as.old_finished }
								status={ as.verdict }
							/>
							<p className="seoc-muted">
								{ sprintf(
									/* translators: 1: completed, 2: failed, 3: pending */
									__(
										'%1$s completed, %2$s failed (kept for diagnosis), %3$s waiting. Only completed and cancelled jobs are deleted.',
										'seo-console'
									),
									formatNumber( as.complete ),
									formatNumber( as.failed ),
									formatNumber( as.pending )
								) }
							</p>
							{ as.groups.length > 0 && (
								<ul className="seoc-plain-list">
									{ as.groups.map( ( g ) => (
										<li key={ g.group || 'none' }>
											<code>
												{ g.group ||
													__(
														'(no group)',
														'seo-console'
													) }
											</code>
											{ `: ${ formatNumber(
												g.old_finished
											) }` }
										</li>
									) ) }
								</ul>
							) }
							<Cleanup
								action="action_scheduler"
								label={ __(
									'Delete old finished jobs',
									'seo-console'
								) }
								count={ as.old_finished }
								busy={ busy }
								onRun={ run }
							/>
						</>
					) : (
						<StatusBadge
							status="unknown"
							label={ __(
								'Action Scheduler tables not found',
								'seo-console'
							) }
						/>
					) }
				</Card>

				<Card
					title={ __( 'Orphaned post data', 'seo-console' ) }
					headingLevel={ 3 }
				>
					<MetricTile
						label={ __(
							'Meta rows of deleted posts',
							'seo-console'
						) }
						value={ r.orphaned_postmeta.count }
						status={ r.orphaned_postmeta.verdict }
					/>
					<Cleanup
						action="orphaned_postmeta"
						label={ __(
							'Delete orphaned post meta',
							'seo-console'
						) }
						count={ r.orphaned_postmeta.count }
						busy={ busy }
						onRun={ run }
					/>
				</Card>

				<Card
					title={ __( 'Post revisions', 'seo-console' ) }
					headingLevel={ 3 }
				>
					<MetricTile
						label={ __( 'Stored revisions', 'seo-console' ) }
						value={ r.revisions.count }
						status={ r.revisions.verdict }
					/>
					<p className="seoc-muted">
						{ __(
							'Turn on the "Limit post revisions" tweak to keep only the last 10 per post from now on.',
							'seo-console'
						) }
					</p>
				</Card>
			</div>
		</>
	);
}
