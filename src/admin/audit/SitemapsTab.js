/**
 * Sitemaps: discovered files, validation checks and the crawl ↔ sitemap cross-check.
 */
import { __, sprintf } from '@wordpress/i18n';

import {
	Card,
	EmptyState,
	ErrorNotice,
	Loading,
	StatusBadge,
	formatDate,
	formatNumber,
	shorten,
	useAsync,
} from '../shared';
import { getSitemaps } from './api';
import Checks from './Checks';

const TYPE_LABELS = {
	index: () => __( 'Sitemap index', 'seo-console' ),
	urlset: () => __( 'URL list', 'seo-console' ),
	gz: () => __( 'Compressed (not checked)', 'seo-console' ),
	invalid: () => __( 'Invalid', 'seo-console' ),
	error: () => __( 'Could not load', 'seo-console' ),
};

function CrossList( { title, help, bucket, good } ) {
	if ( ! bucket ) {
		return null;
	}
	return (
		<details className="seoc-audit-cross">
			<summary>
				<StatusBadge
					status={ bucket.count ? good : 'good' }
					label={ formatNumber( bucket.count ) }
				/>{ ' ' }
				{ title }
			</summary>
			<p className="seoc-muted">{ help }</p>
			{ bucket.urls.length > 0 && (
				<ul className="seoc-plain-list seoc-small">
					{ bucket.urls.map( ( url ) => (
						<li key={ url }>
							<a href={ url } target="_blank" rel="noreferrer">
								{ shorten( url, 90 ) }
							</a>
						</li>
					) ) }
				</ul>
			) }
		</details>
	);
}

/**
 * @return {JSX.Element} Tab.
 */
export default function SitemapsTab() {
	const data = useAsync( ( signal ) => getSitemaps( signal ), [] );
	if ( data.error ) {
		return <ErrorNotice error={ data.error } onRetry={ data.reload } />;
	}
	if ( ! data.data ) {
		return <Loading />;
	}
	const { sitemap, checks, cross_check: cross } = data.data;
	if ( ! sitemap ) {
		return (
			<EmptyState
				title={ __( 'No sitemap analysis yet', 'seo-console' ) }
			>
				{ __(
					'Run an audit: it finds your sitemaps and checks them against the pages it visits.',
					'seo-console'
				) }
			</EmptyState>
		);
	}
	return (
		<div className="seoc-audit-sitemaps">
			<Card
				title={ __( 'Your sitemaps', 'seo-console' ) }
				description={ sprintf(
					/* translators: 1: number of URLs, 2: date */
					__(
						'%1$s addresses listed. From the audit of %2$s.',
						'seo-console'
					),
					formatNumber( sitemap.url_count ),
					formatDate( data.data.analysed_at )
				) }
				flush
			>
				{ sitemap.files.length ? (
					<div className="seoc-table-scroll">
						<table className="widefat striped seoc-table">
							<thead>
								<tr>
									<th scope="col">
										{ __( 'Sitemap', 'seo-console' ) }
									</th>
									<th scope="col">
										{ __( 'Type', 'seo-console' ) }
									</th>
									<th scope="col">
										{ __( 'Entries', 'seo-console' ) }
									</th>
									<th scope="col">
										{ __( 'Problems', 'seo-console' ) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ sitemap.files.map( ( file ) => (
									<tr key={ file.url }>
										<td>
											<a
												href={ file.url }
												target="_blank"
												rel="noreferrer"
											>
												{ shorten( file.url, 70 ) }
											</a>
										</td>
										<td>
											{ TYPE_LABELS[ file.type ]
												? TYPE_LABELS[ file.type ]()
												: file.type }
										</td>
										<td className="seoc-num">
											{ formatNumber( file.count ) }
										</td>
										<td>
											{ Object.keys( file.problems )
												.length
												? Object.keys(
														file.problems
												  ).join( ', ' )
												: '—' }
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) : (
					<p className="seoc-card-body-pad">
						{ __( 'No sitemap found. Tried:', 'seo-console' ) }{ ' ' }
						{ sitemap.tried.join( ', ' ) }
					</p>
				) }
				{ sitemap.capped && (
					<p className="seoc-muted seoc-small">
						{ __(
							'Only the first 20,000 addresses were checked.',
							'seo-console'
						) }
					</p>
				) }
			</Card>

			<Checks
				checks={ checks }
				emptyText={ __( 'Your sitemaps look valid.', 'seo-console' ) }
			/>

			<Card
				title={ __(
					'Sitemap vs. what the crawl found',
					'seo-console'
				) }
				headingLevel={ 3 }
			>
				{ cross ? (
					<>
						<CrossList
							title={ __(
								'Broken addresses in the sitemap',
								'seo-console'
							) }
							help={ __(
								'These return an error. Fix or redirect them; the sitemap updates itself.',
								'seo-console'
							) }
							bucket={ cross.error }
							good="poor"
						/>
						<CrossList
							title={ __(
								'Redirected addresses in the sitemap',
								'seo-console'
							) }
							help={ __(
								'The sitemap should list the final address.',
								'seo-console'
							) }
							bucket={ cross.redirect }
							good="needs-work"
						/>
						<CrossList
							title={ __(
								'Noindex pages in the sitemap',
								'seo-console'
							) }
							help={ __(
								'Either allow indexing or exclude them from the sitemap.',
								'seo-console'
							) }
							bucket={ cross.noindex }
							good="poor"
						/>
						<CrossList
							title={ __(
								'Pages whose canonical is another page',
								'seo-console'
							) }
							help={ __(
								'Only list the main version of each page.',
								'seo-console'
							) }
							bucket={ cross.canonicalised }
							good="needs-work"
						/>
						<CrossList
							title={ __(
								'Sitemap addresses blocked by robots.txt',
								'seo-console'
							) }
							help={ __(
								'Google is invited to pages it is not allowed to read.',
								'seo-console'
							) }
							bucket={ cross.blocked }
							good="poor"
						/>
						<CrossList
							title={ __(
								'Indexable pages missing from the sitemap',
								'seo-console'
							) }
							help={ __(
								'Check the post types and taxonomies included in your SEO plugin sitemap settings.',
								'seo-console'
							) }
							bucket={ cross.missing }
							good="info"
						/>
					</>
				) : (
					<p className="seoc-muted">
						{ __(
							'Available when the audit has finished.',
							'seo-console'
						) }
					</p>
				) }
			</Card>
		</div>
	);
}
