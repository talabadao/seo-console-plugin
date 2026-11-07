/**
 * Cache plugin: read-only check of its settings against a speed checklist.
 */
import { __, sprintf } from '@wordpress/i18n';

import { Card, EmptyState, ErrorNotice, Loading, StatusBadge } from '../shared';

const STATUS = {
	ok: 'good',
	recommend: 'needs-work',
	warning: 'poor',
	unknown: 'unknown',
};

function statusText( status ) {
	switch ( status ) {
		case 'ok':
			return __( 'OK', 'seo-console' );
		case 'recommend':
			return __( 'Recommended', 'seo-console' );
		case 'warning':
			return __( 'Fix this', 'seo-console' );
		default:
			return __( 'Could not check', 'seo-console' );
	}
}

const ORDER = { warning: 0, recommend: 1, unknown: 2, ok: 3 };

export default function CacheTab( { cache } ) {
	if ( cache.loading && ! cache.data ) {
		return <Loading />;
	}
	if ( cache.error ) {
		return <ErrorNotice error={ cache.error } onRetry={ cache.reload } />;
	}
	const data = cache.data;
	const findings = [ ...data.findings ].sort(
		( a, b ) => ORDER[ a.status ] - ORDER[ b.status ]
	);
	return (
		<Card
			title={
				data.audited
					? sprintf(
							/* translators: %s: plugin name */
							__( '%s settings check', 'seo-console' ),
							data.audited.name
					  )
					: __( 'Cache plugin', 'seo-console' )
			}
			description={ __(
				'SEO Console only reads these settings; it never changes another plugin. Use the links to change them yourself.',
				'seo-console'
			) }
		>
			{ data.detected.length > 0 && (
				<p>
					{ __( 'Detected:', 'seo-console' ) }{ ' ' }
					{ data.detected
						.map( ( p ) =>
							p.version ? `${ p.name } ${ p.version }` : p.name
						)
						.join( ', ' ) }
				</p>
			) }
			{ data.note && (
				<EmptyState icon="info-outline">{ data.note }</EmptyState>
			) }
			{ findings.length > 0 && (
				<div className="seoc-table-scroll">
					<table className="widefat striped seoc-table">
						<thead>
							<tr>
								<th scope="col">
									{ __( 'Setting', 'seo-console' ) }
								</th>
								<th scope="col">
									{ __( 'Status', 'seo-console' ) }
								</th>
								<th scope="col">
									{ __( 'What it means', 'seo-console' ) }
								</th>
								<th scope="col">
									{ __(
										'Where to change it',
										'seo-console'
									) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ findings.map( ( f ) => (
								<tr key={ f.id }>
									<th scope="row">{ f.title }</th>
									<td>
										<StatusBadge
											status={ STATUS[ f.status ] }
											label={ statusText( f.status ) }
										/>
									</td>
									<td>{ f.detail }</td>
									<td>
										{ f.how_to }{ ' ' }
										<a href={ f.settings_url }>
											{ __(
												'Open settings',
												'seo-console'
											) }
										</a>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }
		</Card>
	);
}
