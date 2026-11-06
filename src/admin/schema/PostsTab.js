/**
 * Posts tab: which structured data each post already has (and from where), what SEO Console adds,
 * suggestions (rules or AI), the "Needs your input" list and the approval queue for this module.
 */
import {
	Button,
	CheckboxControl,
	Notice,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import {
	Card,
	EmptyState,
	ErrorNotice,
	Loading,
	Pagination,
	SuggestionList,
	formatRelative,
	getUrlParams,
	setUrlParams,
	useAsync,
} from '../shared';
import PostDetail from './PostDetail';
import TypeBadges from './TypeBadges';
import { getPosts, suggest } from './api';

const PER_PAGE = 20;
const HIDDEN_TYPES = [ 'WebSite', 'WebPage', 'Organization', 'Person' ];

/**
 * Types already on the page, grouped so each type shows once with its sources in the tooltip.
 *
 * @param {Array} detected Findings.
 * @return {Array} Badge items.
 */
function detectedBadges( detected ) {
	const byType = {};
	detected
		.filter( ( d ) => d.source !== 'seoc' )
		.filter( ( d ) => ! HIDDEN_TYPES.includes( d.type ) )
		.forEach( ( d ) => {
			byType[ d.type ] = byType[ d.type ] || {
				type: d.type,
				label: d.label,
				sources: [],
			};
			byType[ d.type ].sources.push( d.source_label );
		} );
	return Object.values( byType ).map( ( b ) => ( {
		type: b.type,
		label: b.label,
		title: sprintf(
			/* translators: %s: list of sources */
			__( 'Output by %s', 'seo-console' ),
			b.sources.join( ', ' )
		),
	} ) );
}

function oursBadges( row ) {
	const items = row.ours.map( ( type ) => ( {
		type,
		variant: 'seoc',
		title: __( 'Approved in SEO Console', 'seo-console' ),
	} ) );
	if ( row.pending_suggestion ) {
		row.pending_suggestion.types
			.filter( ( type ) => ! row.ours.includes( type ) )
			.forEach( ( type ) =>
				items.push( {
					type,
					variant: 'pending',
					label: sprintf(
						/* translators: %s: schema type */
						__( '%s (waiting for review)', 'seo-console' ),
						type
					),
				} )
			);
	}
	return items;
}

/**
 * Turns suggest results into one plain sentence.
 *
 * @param {Object} data Response of POST schema/suggest.
 * @return {{status: string, message: string}} Notice.
 */
function summarise( data ) {
	if ( data.queued ) {
		return {
			status: 'info',
			message: sprintf(
				/* translators: %d: number of posts */
				_n(
					'%d post is being checked in the background. Suggestions will appear below as they are ready.',
					'%d posts are being checked in the background. Suggestions will appear below as they are ready.',
					data.count,
					'seo-console'
				),
				data.count
			),
		};
	}
	const results = data.results || [];
	const count = ( status ) =>
		results.filter( ( r ) => r.status === status ).length;
	const parts = [];
	if ( count( 'suggested' ) ) {
		parts.push(
			sprintf(
				/* translators: %d: number of posts */
				_n(
					'%d post has a new suggestion to review.',
					'%d posts have new suggestions to review.',
					count( 'suggested' ),
					'seo-console'
				),
				count( 'suggested' )
			)
		);
	}
	if ( count( 'needs_input' ) ) {
		parts.push(
			sprintf(
				/* translators: %d: number of posts */
				_n(
					'%d post needs your input before anything can be added.',
					'%d posts need your input before anything can be added.',
					count( 'needs_input' ),
					'seo-console'
				),
				count( 'needs_input' )
			)
		);
	}
	if ( count( 'nothing_new' ) ) {
		parts.push(
			sprintf(
				/* translators: %d: number of posts */
				_n(
					'Nothing new found for %d post.',
					'Nothing new found for %d posts.',
					count( 'nothing_new' ),
					'seo-console'
				),
				count( 'nothing_new' )
			)
		);
	}
	const errors = results.filter( ( r ) => r.error );
	errors.forEach( ( r ) => parts.push( r.error.message ) );
	return {
		status: errors.length ? 'warning' : 'success',
		message: parts.join( ' ' ),
	};
}

function NeedsInput( { onOpen, refreshKey } ) {
	const list = useAsync(
		( signal ) =>
			getPosts( { status: 'needs_input', per_page: 20 }, signal ),
		[ refreshKey ]
	);
	if ( list.loading || list.error || ! list.data || ! list.data.total ) {
		return null;
	}
	return (
		<Card
			title={ __( 'Needs your input', 'seo-console' ) }
			tone="attention"
			description={ __(
				'SEO Console found these items but some facts are missing, so nothing was suggested yet. Add the missing details to the post (for example the video title or upload date), then suggest again.',
				'seo-console'
			) }
		>
			<ul className="seoc-schema-list">
				{ list.data.items.map( ( row ) => (
					<li key={ row.id }>
						<strong>{ row.title }</strong>
						{ ': ' }
						{ row.needs_input
							.map( ( item ) =>
								item.missing.length
									? sprintf(
											/* translators: 1: schema type label, 2: list of missing facts */
											__(
												'%1$s is missing %2$s',
												'seo-console'
											),
											item.label,
											item.missing.join( ', ' )
									  )
									: item.label
							)
							.join( '; ' ) }{ ' ' }
						<Button
							variant="link"
							onClick={ () => onOpen( row.id ) }
						>
							{ __( 'Details', 'seo-console' ) }
						</Button>
						{ row.edit_link && (
							<>
								{ ' · ' }
								<a href={ row.edit_link }>
									{ __( 'Edit post', 'seo-console' ) }
								</a>
							</>
						) }
					</li>
				) ) }
			</ul>
		</Card>
	);
}

export default function PostsTab( { addNotice } ) {
	const params = getUrlParams();
	const [ filters, setFilters ] = useState( {
		post_type: params.get( 'post_type' ) || '',
		status: params.get( 'status' ) || '',
		search: params.get( 's' ) || '',
		page: Math.max( 1, parseInt( params.get( 'paged' ), 10 ) || 1 ),
	} );
	const [ searchText, setSearchText ] = useState( filters.search );
	const [ selected, setSelected ] = useState( [] );
	const [ useAi, setUseAi ] = useState( false );
	const [ busy, setBusy ] = useState( null );
	const [ openId, setOpenId ] = useState(
		parseInt( params.get( 'post' ), 10 ) || null
	);
	const [ refreshKey, setRefreshKey ] = useState( 0 );
	// Remounts the approval queue only after new suggestions were made (keeps its own messages otherwise).
	const [ queueKey, setQueueKey ] = useState( 0 );

	const list = useAsync(
		( signal ) => getPosts( { ...filters, per_page: PER_PAGE }, signal ),
		[ JSON.stringify( filters ), refreshKey ]
	);
	const data = list.data;

	const update = ( next ) => {
		const merged = { ...filters, page: 1, ...next };
		setFilters( merged );
		setSelected( [] );
		setUrlParams( {
			post_type: merged.post_type,
			status: merged.status,
			s: merged.search,
			paged: merged.page > 1 ? merged.page : '',
		} );
	};

	const open = ( id ) => {
		setOpenId( id );
		setUrlParams( { post: id || '' } );
	};

	const refresh = () => setRefreshKey( ( k ) => k + 1 );

	const runSuggest = async ( ids, key ) => {
		setBusy( key );
		try {
			const result = await suggest( ids, useAi );
			const { status, message } = summarise( result );
			addNotice( status, message );
			refresh();
			setQueueKey( ( k ) => k + 1 );
		} catch ( error ) {
			addNotice( 'error', error.message );
		} finally {
			setBusy( null );
		}
	};

	const items = data ? data.items : [];
	const allSelected =
		items.length > 0 && items.every( ( r ) => selected.includes( r.id ) );
	const batch = data && data.batch;
	const batchRunning = batch && ! batch.finished_at;

	return (
		<>
			<NeedsInput onOpen={ open } refreshKey={ refreshKey } />

			<Card
				title={ __( 'Your posts and pages', 'seo-console' ) }
				description={ __(
					'"Already on the page" is structured data your SEO plugin, theme or content outputs; SEO Console never adds those types again. "SEO Console" is what you approved here.',
					'seo-console'
				) }
			>
				<div className="seoc-schema-toolbar">
					<SelectControl
						label={ __( 'Type', 'seo-console' ) }
						value={ filters.post_type }
						options={ [
							{
								value: '',
								label: __( 'All types', 'seo-console' ),
							},
							...( data ? data.post_types : [] ),
						] }
						onChange={ ( v ) => update( { post_type: v } ) }
						__nextHasNoMarginBottom
					/>
					<SelectControl
						label={ __( 'Show', 'seo-console' ) }
						value={ filters.status }
						options={ [
							{ value: '', label: __( 'All', 'seo-console' ) },
							{
								value: 'has_ours',
								label: __(
									'With SEO Console structured data',
									'seo-console'
								),
							},
							{
								value: 'none',
								label: __(
									'Without SEO Console structured data',
									'seo-console'
								),
							},
							{
								value: 'needs_input',
								label: __( 'Needs your input', 'seo-console' ),
							},
						] }
						onChange={ ( v ) => update( { status: v } ) }
						__nextHasNoMarginBottom
					/>
					<form
						onSubmit={ ( e ) => {
							e.preventDefault();
							update( { search: searchText } );
						} }
					>
						<TextControl
							label={ __( 'Search', 'seo-console' ) }
							value={ searchText }
							onChange={ setSearchText }
							__nextHasNoMarginBottom
						/>
					</form>
					<CheckboxControl
						label={ __( 'Use AI too', 'seo-console' ) }
						help={
							data && ! data.ai
								? __(
										'AI is off: switch on AI suggestions and add a Claude API key in Settings.',
										'seo-console'
								  )
								: __(
										'AI can find FAQs, services, events and recipes in longer text. One post is checked right away; more run in the background.',
										'seo-console'
								  )
						}
						checked={ useAi && !! ( data && data.ai ) }
						disabled={ ! data || ! data.ai }
						onChange={ setUseAi }
						__nextHasNoMarginBottom
					/>
					<Button
						variant="primary"
						disabled={ ! selected.length || !! busy }
						isBusy={ busy === 'bulk' }
						onClick={ () => runSuggest( selected, 'bulk' ) }
					>
						{ selected.length
							? sprintf(
									/* translators: %d: number of selected posts */
									__(
										'Suggest for %d selected',
										'seo-console'
									),
									selected.length
							  )
							: __( 'Suggest for selected', 'seo-console' ) }
					</Button>
				</div>

				{ batchRunning && (
					<Notice status="info" isDismissible={ false }>
						{ sprintf(
							/* translators: 1: posts done, 2: total posts */
							__(
								'Checking posts in the background: %1$d of %2$d done.',
								'seo-console'
							),
							batch.done,
							batch.total
						) }{ ' ' }
						<Button variant="link" onClick={ refresh }>
							{ __( 'Refresh', 'seo-console' ) }
						</Button>
					</Notice>
				) }

				{ list.error && (
					<ErrorNotice
						error={ list.error }
						onRetry={ list.reload }
						title={ __(
							'Could not load your posts.',
							'seo-console'
						) }
					/>
				) }
				{ list.loading && ! data && (
					<Loading>{ __( 'Loading posts…', 'seo-console' ) }</Loading>
				) }
				{ data && ! items.length && (
					<EmptyState title={ __( 'No posts found', 'seo-console' ) }>
						{ __( 'Try another type or filter.', 'seo-console' ) }
					</EmptyState>
				) }
				{ data && items.length > 0 && (
					<div className="seoc-table-scroll">
						<table
							className="widefat striped seoc-table"
							aria-busy={ list.loading }
						>
							<caption className="screen-reader-text">
								{ __(
									'Posts and their structured data',
									'seo-console'
								) }
							</caption>
							<thead>
								<tr>
									<td className="check-column">
										<input
											type="checkbox"
											aria-label={ __(
												'Select all',
												'seo-console'
											) }
											checked={ allSelected }
											onChange={ () =>
												setSelected(
													allSelected
														? []
														: items.map(
																( r ) => r.id
														  )
												)
											}
										/>
									</td>
									<th scope="col">
										{ __( 'Post', 'seo-console' ) }
									</th>
									<th scope="col">
										{ __(
											'Already on the page',
											'seo-console'
										) }
									</th>
									<th scope="col">
										{ __( 'SEO Console', 'seo-console' ) }
									</th>
									<th scope="col">
										<span className="screen-reader-text">
											{ __( 'Actions', 'seo-console' ) }
										</span>
									</th>
								</tr>
							</thead>
							<tbody>
								{ items.map( ( row ) => (
									<tr key={ row.id }>
										<th
											scope="row"
											className="check-column"
										>
											<input
												type="checkbox"
												aria-label={ sprintf(
													/* translators: %s: post title */
													__(
														'Select %s',
														'seo-console'
													),
													row.title
												) }
												checked={ selected.includes(
													row.id
												) }
												onChange={ () =>
													setSelected( ( s ) =>
														s.includes( row.id )
															? s.filter(
																	( id ) =>
																		id !==
																		row.id
															  )
															: [ ...s, row.id ]
													)
												}
											/>
										</th>
										<td>
											<strong>{ row.title }</strong>
											<div className="seoc-muted seoc-small">
												{ row.post_type_label }
												{ row.status !== 'publish'
													? ` · ${ row.status }`
													: '' }
												{ ' · ' }
												{ formatRelative(
													row.modified
												) }
											</div>
										</td>
										<td>
											<TypeBadges
												items={ detectedBadges(
													row.detected
												) }
												empty={ __(
													'Nothing found',
													'seo-console'
												) }
											/>
										</td>
										<td>
											<TypeBadges
												items={ oursBadges( row ) }
											/>
											{ row.needs_input.length > 0 && (
												<div className="seoc-small">
													{ sprintf(
														/* translators: %d: number of items */
														_n(
															'%d item needs your input',
															'%d items need your input',
															row.needs_input
																.length,
															'seo-console'
														),
														row.needs_input.length
													) }
												</div>
											) }
										</td>
										<td className="seoc-table__actions">
											<Button
												variant="secondary"
												size="compact"
												onClick={ () =>
													open(
														openId === row.id
															? null
															: row.id
													)
												}
												aria-expanded={
													openId === row.id
												}
											>
												{ __(
													'Details',
													'seo-console'
												) }
											</Button>{ ' ' }
											<Button
												variant="tertiary"
												size="compact"
												disabled={ !! busy }
												isBusy={ busy === row.id }
												onClick={ () =>
													runSuggest(
														[ row.id ],
														row.id
													)
												}
											>
												{ __(
													'Suggest',
													'seo-console'
												) }
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
						disabled={ list.loading }
						onChange={ ( page ) => update( { page } ) }
					/>
				) }
			</Card>

			{ openId && (
				<PostDetail
					key={ `${ openId }-${ refreshKey }` }
					id={ openId }
					onClose={ () => open( null ) }
					onChanged={ refresh }
					addNotice={ addNotice }
				/>
			) }

			<Card
				title={ __( 'Waiting for your review', 'seo-console' ) }
				description={ __(
					'Approve a suggestion to add its structured data to the page. Every change is logged and can be undone.',
					'seo-console'
				) }
			>
				<SuggestionList
					key={ queueKey }
					query={ { module: 'schema', status: 'pending' } }
					perPage={ 10 }
					onChanged={ refresh }
					emptyMessage={ __(
						'No structured data suggestions are waiting. Select posts above and choose "Suggest".',
						'seo-console'
					) }
				/>
			</Card>
		</>
	);
}
