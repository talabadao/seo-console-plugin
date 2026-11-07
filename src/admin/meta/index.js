/**
 * SEO Meta page: SEO title / description / image alt status per post, AI suggestions for one post (inline) or
 * many (background job with progress), and the meta suggestions waiting for review.
 */
import {
	Button,
	Notice,
	SearchControl,
	SelectControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import {
	Card,
	EmptyState,
	ErrorNotice,
	Loading,
	LiveRegion,
	Page,
	Pagination,
	StatusBadge,
	SuggestionList,
	adminPageUrl,
	apiGet,
	apiPost,
	formatDate,
	formatNumber,
	formatRelative,
	getConfig,
	getUrlParams,
	mount,
	normaliseError,
	setUrlParams,
	useAsync,
	useNotices,
} from '../shared';
import './style.scss';

const PER_PAGE = 20;
const POLL_MS = 5000;

const BADGE = {
	good: 'good',
	short: 'needs-work',
	long: 'needs-work',
	missing: 'poor',
	unavailable: 'unknown',
};

function issueOptions( seoFields ) {
	if ( ! seoFields ) {
		return [
			{ value: '', label: __( 'All posts', 'seo-console' ) },
			{
				value: 'missing_alt',
				label: __( 'Images without alt text', 'seo-console' ),
			},
		];
	}
	return [
		{ value: '', label: __( 'All posts', 'seo-console' ) },
		{
			value: 'missing_title',
			label: __( 'No SEO title', 'seo-console' ),
		},
		{
			value: 'missing_description',
			label: __( 'No meta description', 'seo-console' ),
		},
		{
			value: 'too_long',
			label: __( 'Title or description too long', 'seo-console' ),
		},
		{
			value: 'missing_alt',
			label: __( 'Images without alt text', 'seo-console' ),
		},
	];
}

function lengthLabel( status, length ) {
	switch ( status ) {
		case 'unavailable':
			return __( 'Needs Rank Math or Yoast', 'seo-console' );
		case 'missing':
			return __( 'Not set', 'seo-console' );
		case 'short':
			/* translators: %d: number of characters */
			return sprintf( __( 'Short · %d chars', 'seo-console' ), length );
		case 'long':
			/* translators: %d: number of characters */
			return sprintf( __( 'Long · %d chars', 'seo-console' ), length );
		default:
			/* translators: %d: number of characters */
			return sprintf( __( 'Good · %d chars', 'seo-console' ), length );
	}
}

function TitleCell( { field } ) {
	if ( field.status === 'unavailable' ) {
		return (
			<StatusBadge
				status="unknown"
				label={ lengthLabel( field.status ) }
			/>
		);
	}
	return (
		<>
			<StatusBadge
				status={ BADGE[ field.status ] || 'unknown' }
				label={ lengthLabel( field.status, field.length ) }
			/>
			<div className="seoc-meta__value">
				{ field.status === 'missing' ? (
					<span className="seoc-muted">
						{ field.resolved
							? sprintf(
									/* translators: %s: title the SEO plugin outputs from its template */
									__( 'Template: %s', 'seo-console' ),
									field.resolved
							  )
							: __( 'Not set', 'seo-console' ) }
					</span>
				) : (
					field.resolved || field.value
				) }
			</div>
		</>
	);
}

function DescriptionCell( { field } ) {
	if ( field.status === 'unavailable' ) {
		return (
			<StatusBadge
				status="unknown"
				label={ lengthLabel( field.status ) }
			/>
		);
	}
	return (
		<>
			<StatusBadge
				status={ BADGE[ field.status ] || 'unknown' }
				label={ lengthLabel( field.status, field.length ) }
			/>
			{ field.value && (
				<div className="seoc-meta__value">{ field.value }</div>
			) }
		</>
	);
}

function ImagesCell( { images } ) {
	if ( ! images.total ) {
		return (
			<span
				className="seoc-muted"
				title={ __( 'No images', 'seo-console' ) }
			>
				–
			</span>
		);
	}
	if ( ! images.missing_alt ) {
		return (
			<StatusBadge
				status="good"
				label={ sprintf(
					/* translators: %d: number of images */
					__( '%d with alt', 'seo-console' ),
					images.total
				) }
			/>
		);
	}
	return (
		<StatusBadge
			status="poor"
			label={ sprintf(
				/* translators: 1: images without alt text, 2: all images */
				__( '%1$d of %2$d missing', 'seo-console' ),
				images.missing_alt,
				images.total
			) }
		/>
	);
}

function JobProgress( { status, onRefresh } ) {
	if ( ! status ) {
		return null;
	}
	const active = status.queued > 0;
	if ( ! active && ! status.stopped && ! status.done && ! status.failed ) {
		return null;
	}
	return (
		<Card
			title={ __( 'Background suggestions', 'seo-console' ) }
			tone={ status.stopped ? 'attention' : undefined }
			actions={
				<Button variant="tertiary" onClick={ onRefresh }>
					{ __( 'Refresh', 'seo-console' ) }
				</Button>
			}
		>
			{ status.stopped && (
				<Notice status="warning" isDismissible={ false }>
					<strong>{ __( 'Paused.', 'seo-console' ) }</strong>{ ' ' }
					{ status.stopped.message }
				</Notice>
			) }
			<p>
				{ active
					? sprintf(
							/* translators: 1: posts waiting, 2: posts done, 3: posts failed */
							__(
								'%1$d posts waiting · %2$d done · %3$d failed in the last 7 days. Each batch runs in the background for up to 20 seconds; this updates by itself.',
								'seo-console'
							),
							status.queued,
							status.done,
							status.failed
					  )
					: sprintf(
							/* translators: 1: posts done, 2: posts failed, 3: suggestions created */
							__(
								'Last 7 days: %1$d posts done, %2$d failed, %3$d suggestions created. Nothing is waiting.',
								'seo-console'
							),
							status.done,
							status.failed,
							status.suggestions_created
					  ) }
			</p>
			{ active && ! status.job_scheduled && ! status.stopped && (
				<p className="seoc-muted seoc-small">
					{ __(
						'No background run is scheduled right now. WordPress runs background tasks when someone visits the site; on low-traffic sites this can take a few minutes.',
						'seo-console'
					) }
				</p>
			) }
			{ status.recent_failures && status.recent_failures.length > 0 && (
				<details>
					<summary>
						{ __( 'Recent failures', 'seo-console' ) }
					</summary>
					<ul className="seoc-plain-list">
						{ status.recent_failures.map( ( f ) => (
							<li key={ `${ f.post_id }-${ f.at }` }>
								<strong>
									{ f.title ||
										sprintf(
											/* translators: %d: post ID */
											__( 'Post #%d', 'seo-console' ),
											f.post_id
										) }
								</strong>{ ' ' }
								<span title={ formatDate( f.at ) }>
									({ formatRelative( f.at ) })
								</span>
								: { f.message }
							</li>
						) ) }
					</ul>
				</details>
			) }
		</Card>
	);
}

function App() {
	const config = getConfig();
	const params = getUrlParams();
	const [ filters, setFilters ] = useState( {
		post_type: params.get( 'post_type' ) || '',
		issue: params.get( 'issue' ) || '',
		search: params.get( 's' ) || '',
		page: Math.max( 1, parseInt( params.get( 'paged' ), 10 ) || 1 ),
	} );
	const [ searchText, setSearchText ] = useState( filters.search );
	const [ selected, setSelected ] = useState( [] );
	const [ rowBusy, setRowBusy ] = useState( {} );
	const [ bulkBusy, setBulkBusy ] = useState( false );
	const [ reviewKey, setReviewKey ] = useState( 0 );
	const [ announce, setAnnounce ] = useState( '' );
	const { notices, addNotice, removeNotice } = useNotices();

	const query = { ...filters, per_page: PER_PAGE };
	const posts = useAsync(
		( signal ) => apiGet( 'meta/posts', query, signal ),
		[ JSON.stringify( query ) ]
	);
	const status = useAsync( ( signal ) =>
		apiGet( 'meta/status', {}, signal )
	);

	const statusData = status.data;
	const polling =
		!! statusData && statusData.queued > 0 && ! statusData.stopped;
	useEffect( () => {
		if ( ! polling ) {
			return undefined;
		}
		const timer = setInterval( () => {
			status.reload();
			posts.reload();
			setReviewKey( ( k ) => k + 1 );
		}, POLL_MS );
		return () => clearInterval( timer );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ polling ] );

	const changeFilters = ( next ) => {
		const merged = { ...filters, page: 1, ...next };
		setFilters( merged );
		setSelected( [] );
		setUrlParams( {
			post_type: merged.post_type,
			issue: merged.issue,
			s: merged.search,
			paged: merged.page > 1 ? merged.page : '',
		} );
	};

	const data = posts.data;
	const items = data ? data.items : [];
	const aiReady = data ? !! data.ai_configured : config.aiConfigured;
	const seoPlugin = data ? data.seo_plugin : config.seoPlugin;

	const seoFields = data ? data.seo_fields !== false : seoPlugin !== 'none';
	const hasMissingAlt = ( item ) =>
		!! item.images && item.images.missing_alt > 0;
	const rowLabel = ( item ) => {
		if ( rowBusy[ item.id ] ) {
			return __( 'Asking Claude…', 'seo-console' );
		}
		return seoFields
			? __( 'Suggest with AI', 'seo-console' )
			: __( 'Suggest alt texts', 'seo-console' );
	};
	const fieldsFor = ( item ) => {
		if ( ! seoFields ) {
			return hasMissingAlt( item ) ? [ 'image_alt' ] : [];
		}
		return hasMissingAlt( item )
			? [ 'title', 'description', 'image_alt' ]
			: [ 'title', 'description' ];
	};

	const suggestOne = async ( item ) => {
		setRowBusy( ( s ) => ( { ...s, [ item.id ]: true } ) );
		try {
			const res = await apiPost( 'meta/suggest', {
				post_ids: [ item.id ],
				fields: fieldsFor( item ),
			} );
			addNotice(
				res.created ? 'success' : 'warning',
				`${ item.title }: ${ res.message }`,
				{
					actions: res.created
						? [
								{
									label: __( 'Review below', 'seo-console' ),
									url: '#seoc-meta-review',
								},
						  ]
						: [],
				}
			);
			setAnnounce( res.message );
			posts.reload();
			setReviewKey( ( k ) => k + 1 );
		} catch ( e ) {
			const err = normaliseError( e );
			addNotice( 'error', `${ item.title }: ${ err.message }` );
			setAnnounce( err.message );
		}
		setRowBusy( ( s ) => ( { ...s, [ item.id ]: false } ) );
	};

	const suggestSelected = async () => {
		if ( ! selected.length ) {
			return;
		}
		if ( selected.length === 1 ) {
			const item = items.find( ( i ) => i.id === selected[ 0 ] );
			if ( item ) {
				await suggestOne( item );
			}
			setSelected( [] );
			return;
		}
		setBulkBusy( true );
		try {
			const res = await apiPost( 'meta/suggest', {
				post_ids: selected,
				fields: seoFields
					? [ 'title', 'description', 'image_alt' ]
					: [ 'image_alt' ],
			} );
			const queuedMessage = sprintf(
				/* translators: %d: number of posts */
				_n(
					'%d post queued. Suggestions appear below as each one is done.',
					'%d posts queued. Suggestions appear below as each one is done.',
					res.queued,
					'seo-console'
				),
				res.queued
			);
			const message = res.note
				? `${ queuedMessage } ${ res.note }`
				: queuedMessage;
			addNotice( 'success', message );
			setAnnounce( message );
			setSelected( [] );
			status.reload();
		} catch ( e ) {
			const err = normaliseError( e );
			addNotice( 'error', err.message );
			setAnnounce( err.message );
		}
		setBulkBusy( false );
	};

	const toggle = ( id, on ) =>
		setSelected( ( s ) =>
			on ? [ ...new Set( [ ...s, id ] ) ] : s.filter( ( x ) => x !== id )
		);
	const allOnPage =
		items.length > 0 && items.every( ( i ) => selected.includes( i.id ) );

	const typeOptions = [
		{ value: '', label: __( 'All types', 'seo-console' ) },
		...( data && data.post_types ? data.post_types : [] ),
	];

	return (
		<Page
			title={ __( 'SEO Meta', 'seo-console' ) }
			description={ __(
				'SEO titles, meta descriptions and image alt texts of your published content. Claude proposes better ones; nothing changes until you approve a suggestion.',
				'seo-console'
			) }
			meta={
				<a href={ adminPageUrl( 'suggestions', { module: 'meta' } ) }>
					{ __( 'Review all meta suggestions', 'seo-console' ) }
				</a>
			}
			notices={ notices }
			onRemoveNotice={ removeNotice }
		>
			<LiveRegion>{ announce }</LiveRegion>

			{ ! aiReady && (
				<Card>
					<EmptyState
						icon="admin-network"
						title={ __(
							'Connect Claude to get suggestions',
							'seo-console'
						) }
						actions={
							<Button
								variant="primary"
								href={ adminPageUrl( 'settings' ) }
							>
								{ __( 'Open Settings', 'seo-console' ) }
							</Button>
						}
					>
						{ __(
							'Add your Claude API key (or raise this month’s AI budget) in Settings. You can still check which posts need attention below.',
							'seo-console'
						) }
					</EmptyState>
				</Card>
			) }

			{ ! seoFields && (
				<Notice status="warning" isDismissible={ false }>
					<p>
						<strong>
							{ __(
								'SEO titles and meta descriptions need Rank Math or Yoast SEO.',
								'seo-console'
							) }
						</strong>{ ' ' }
						{ __(
							'Those plugins store the title and description search engines show, and neither is active on this site. SEO Console does not add its own, so it cannot check or suggest them until you activate one. Image alt texts work without them.',
							'seo-console'
						) }
					</p>
					<p>
						<a href={ `${ config.adminUrl }plugins.php` }>
							{ __( 'Go to Plugins', 'seo-console' ) }
						</a>
					</p>
				</Notice>
			) }

			<JobProgress status={ statusData } onRefresh={ status.reload } />

			<Card title={ __( 'Posts', 'seo-console' ) } flush>
				<div className="seoc-toolbar">
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Show', 'seo-console' ) }
						value={ filters.issue }
						options={ issueOptions( seoFields ) }
						onChange={ ( v ) => changeFilters( { issue: v } ) }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Type', 'seo-console' ) }
						value={ filters.post_type }
						options={ typeOptions }
						onChange={ ( v ) => changeFilters( { post_type: v } ) }
					/>
					<form
						className="seoc-meta__search"
						onSubmit={ ( e ) => {
							e.preventDefault();
							changeFilters( { search: searchText } );
						} }
					>
						<SearchControl
							__nextHasNoMarginBottom
							label={ __( 'Search posts', 'seo-console' ) }
							value={ searchText }
							onChange={ ( v ) => {
								setSearchText( v );
								if ( ! v ) {
									changeFilters( { search: '' } );
								}
							} }
						/>
					</form>
					<Button
						variant="secondary"
						onClick={ suggestSelected }
						isBusy={ bulkBusy }
						disabled={ ! aiReady || ! selected.length || bulkBusy }
					>
						{ selected.length
							? sprintf(
									/* translators: %d: number of selected posts */
									__(
										'Suggest for selected (%d)',
										'seo-console'
									),
									selected.length
							  )
							: __( 'Suggest for selected', 'seo-console' ) }
					</Button>
				</div>

				<ErrorNotice
					title={ __( 'Could not load posts.', 'seo-console' ) }
					error={ posts.error }
					onRetry={ posts.reload }
				/>
				{ posts.loading && ! data && (
					<Loading>
						{ __( 'Checking your posts…', 'seo-console' ) }
					</Loading>
				) }
				{ data && ! items.length && (
					<EmptyState
						icon="yes-alt"
						title={
							filters.issue
								? __(
										'No posts with this issue',
										'seo-console'
								  )
								: __(
										'No published posts found',
										'seo-console'
								  )
						}
					>
						{ filters.issue
							? __(
									'Nice. Pick another filter to see other posts.',
									'seo-console'
							  )
							: __(
									'Only published posts, pages and products are listed.',
									'seo-console'
							  ) }
					</EmptyState>
				) }
				{ data && data.scan_limited && (
					<p className="seoc-muted seoc-small seoc-meta__note">
						{ __(
							'Only the 1,000 most recently modified posts are checked for this filter.',
							'seo-console'
						) }
					</p>
				) }

				{ items.length > 0 && (
					<div className="seoc-table-scroll">
						<table
							className="widefat striped seoc-table seoc-meta__table"
							aria-busy={ posts.loading }
						>
							<caption className="screen-reader-text">
								{ __( 'SEO meta per post', 'seo-console' ) }
							</caption>
							<thead>
								<tr>
									<td className="check-column">
										<input
											type="checkbox"
											id="seoc-meta-select-all"
											checked={ allOnPage }
											onChange={ ( e ) =>
												items.forEach( ( i ) =>
													toggle(
														i.id,
														e.target.checked
													)
												)
											}
										/>
										<label
											htmlFor="seoc-meta-select-all"
											className="screen-reader-text"
										>
											{ __(
												'Select all on this page',
												'seo-console'
											) }
										</label>
									</td>
									<th scope="col">
										{ __( 'Post', 'seo-console' ) }
									</th>
									<th scope="col">
										{ __( 'SEO title', 'seo-console' ) }
									</th>
									<th scope="col">
										{ __(
											'Meta description',
											'seo-console'
										) }
									</th>
									<th scope="col">
										{ __( 'Image alt', 'seo-console' ) }
									</th>
									<th scope="col" className="seoc-num">
										{ __( 'To review', 'seo-console' ) }
									</th>
									<th scope="col">
										<span className="screen-reader-text">
											{ __( 'Actions', 'seo-console' ) }
										</span>
									</th>
								</tr>
							</thead>
							<tbody>
								{ items.map( ( item ) => (
									<tr key={ item.id }>
										<th
											scope="row"
											className="check-column"
										>
											<input
												type="checkbox"
												id={ `seoc-meta-select-${ item.id }` }
												checked={ selected.includes(
													item.id
												) }
												onChange={ ( e ) =>
													toggle(
														item.id,
														e.target.checked
													)
												}
											/>
											<label
												htmlFor={ `seoc-meta-select-${ item.id }` }
												className="screen-reader-text"
											>
												{ sprintf(
													/* translators: %s: post title */
													__(
														'Select %s',
														'seo-console'
													),
													item.title
												) }
											</label>
										</th>
										<td>
											<strong>
												<a href={ item.edit_link }>
													{ item.title ||
														__(
															'(no title)',
															'seo-console'
														) }
												</a>
											</strong>
											<div className="seoc-muted seoc-small">
												{ item.post_type_label } ·{ ' ' }
												<a
													href={ item.view_link }
													target="_blank"
													rel="noopener noreferrer"
												>
													{ __(
														'View',
														'seo-console'
													) }
												</a>
												{ item.focus_keyword && (
													<>
														{ ' · ' }
														{ sprintf(
															/* translators: %s: focus keyword */
															__(
																'Keyword: %s',
																'seo-console'
															),
															item.focus_keyword
														) }
													</>
												) }
											</div>
										</td>
										<td>
											<TitleCell
												field={ item.seo_title }
											/>
										</td>
										<td>
											<DescriptionCell
												field={ item.description }
											/>
										</td>
										<td>
											<ImagesCell
												images={ item.images }
											/>
										</td>
										<td className="seoc-num">
											{ item.pending_suggestions ? (
												<a href="#seoc-meta-review">
													{ formatNumber(
														item.pending_suggestions
													) }
												</a>
											) : (
												<span className="seoc-muted">
													0
												</span>
											) }
										</td>
										<td className="seoc-table__actions">
											<Button
												variant="secondary"
												size="compact"
												onClick={ () =>
													suggestOne( item )
												}
												isBusy={ !! rowBusy[ item.id ] }
												disabled={
													! aiReady ||
													!! rowBusy[ item.id ] ||
													! fieldsFor( item ).length
												}
											>
												{ rowLabel( item ) }
											</Button>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) }
				{ data && (
					<Pagination
						page={ filters.page }
						perPage={ PER_PAGE }
						total={ data.total }
						disabled={ posts.loading }
						onChange={ ( page ) => changeFilters( { page } ) }
					/>
				) }
			</Card>

			<div id="seoc-meta-review">
				<Card
					title={ __(
						'Meta suggestions waiting for review',
						'seo-console'
					) }
					description={ __(
						'Edit any suggestion before approving. Approved changes are written to your SEO plugin or the post, logged, and can be undone in the change log.',
						'seo-console'
					) }
					actions={
						<a
							href={ adminPageUrl( 'suggestions', {
								module: 'meta',
							} ) }
						>
							{ __( 'Review suggestions', 'seo-console' ) }
						</a>
					}
				>
					<SuggestionList
						key={ reviewKey }
						query={ { module: 'meta' } }
						perPage={ 10 }
						onChanged={ posts.reload }
						emptyMessage={ __(
							'No meta suggestions are waiting. Use "Suggest with AI" on a post above.',
							'seo-console'
						) }
					/>
				</Card>
			</div>
		</Page>
	);
}

mount( App );
