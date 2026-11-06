/**
 * Crawled pages table with filters and a detail panel.
 */
import {
	Button,
	Modal,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	ErrorNotice,
	Loading,
	Pagination,
	StatusBadge,
	formatNumber,
	shorten,
	useAsync,
} from '../shared';
import { getPage, getPages } from './api';
import IssueData from './IssueData';
import {
	SEVERITY_STATUS,
	indexabilityLabel,
	indexabilityStatus,
	severityLabel,
} from './labels';

const PER_PAGE = 25;

const INDEXABILITY = [
	'indexable',
	'noindex',
	'canonicalised',
	'redirect',
	'client_error',
	'server_error',
	'blocked',
	'not_html',
	'error',
];

function statusText( page ) {
	if ( page.crawl_state === 'blocked' ) {
		return __( 'not visited', 'seo-console' );
	}
	if ( page.crawl_state === 'error' ) {
		return __( 'failed', 'seo-console' );
	}
	return page.status_code ? String( page.status_code ) : '—';
}

function Fact( { label, children } ) {
	return (
		<>
			<dt>{ label }</dt>
			<dd>
				{ children === null || children === undefined || children === ''
					? '—'
					: children }
			</dd>
		</>
	);
}

function PageDetail( { pageId, onClose } ) {
	const detail = useAsync(
		( signal ) => getPage( pageId, signal ),
		[ pageId ]
	);
	const page = detail.data ? detail.data.page : null;
	return (
		<Modal
			title={ __( 'Page details', 'seo-console' ) }
			onRequestClose={ onClose }
			className="seoc-app seoc-audit-modal"
			size="large"
		>
			{ detail.error && (
				<ErrorNotice error={ detail.error } onRetry={ detail.reload } />
			) }
			{ ! detail.data && ! detail.error && <Loading /> }
			{ page && (
				<>
					<p>
						<a href={ page.url } target="_blank" rel="noreferrer">
							{ page.url }
						</a>
						{ page.edit_link && (
							<>
								{ ' · ' }
								<a href={ page.edit_link }>
									{ __( 'Edit in WordPress', 'seo-console' ) }
								</a>
							</>
						) }
					</p>
					<dl className="seoc-audit-facts">
						<Fact label={ __( 'Status', 'seo-console' ) }>
							{ statusText( page ) }
							{ page.final_url ? ` → ${ page.final_url }` : '' }
						</Fact>
						<Fact label={ __( 'Indexing', 'seo-console' ) }>
							{ indexabilityLabel( page.indexability ) }
						</Fact>
						<Fact label={ __( 'Title', 'seo-console' ) }>
							{ page.title }
						</Fact>
						<Fact label={ __( 'Meta description', 'seo-console' ) }>
							{ page.meta_description }
						</Fact>
						<Fact label={ __( 'H1', 'seo-console' ) }>
							{ page.h1 }
							{ page.h1_count > 1
								? ` (+${ page.h1_count - 1 })`
								: '' }
						</Fact>
						<Fact label={ __( 'Canonical', 'seo-console' ) }>
							{ page.canonical }
						</Fact>
						<Fact label={ __( 'Robots', 'seo-console' ) }>
							{ [ page.robots_meta, page.x_robots ]
								.filter( Boolean )
								.join( ' · ' ) }
						</Fact>
						<Fact label={ __( 'Words', 'seo-console' ) }>
							{ formatNumber( page.word_count ) }
						</Fact>
						<Fact label={ __( 'Clicks from home', 'seo-console' ) }>
							{ page.depth === null
								? __(
										'not linked from the home page',
										'seo-console'
								  )
								: page.depth }
						</Fact>
						<Fact
							label={ __( 'Links pointing here', 'seo-console' ) }
						>
							{ formatNumber( page.inbound_internal ) }
						</Fact>
						<Fact label={ __( 'In sitemap', 'seo-console' ) }>
							{ page.in_sitemap
								? __( 'Yes', 'seo-console' )
								: __( 'No', 'seo-console' ) }
						</Fact>
						<Fact label={ __( 'Response time', 'seo-console' ) }>
							{ page.response_ms === null
								? null
								: `${ formatNumber( page.response_ms ) } ms` }
						</Fact>
						<Fact label={ __( 'Structured data', 'seo-console' ) }>
							{ ( page.schema_types || [] ).join( ', ' ) }
						</Fact>
					</dl>

					<h3>{ __( 'Issues on this page', 'seo-console' ) }</h3>
					{ detail.data.issues.length ? (
						<ul className="seoc-plain-list">
							{ detail.data.issues.map( ( issue ) => (
								<li key={ issue.id }>
									<StatusBadge
										status={
											SEVERITY_STATUS[ issue.severity ]
										}
										label={ severityLabel(
											issue.severity
										) }
									/>{ ' ' }
									<code>{ issue.rule_id }</code>
									<IssueData data={ issue.data } />
								</li>
							) ) }
						</ul>
					) : (
						<p className="seoc-muted">
							{ __( 'No issues found.', 'seo-console' ) }
						</p>
					) }

					<h3>
						{ sprintf(
							/* translators: %s: number of pages */
							__( 'Pages linking here (%s)', 'seo-console' ),
							formatNumber( detail.data.inbound.length )
						) }
					</h3>
					<ul className="seoc-plain-list seoc-small">
						{ detail.data.inbound.map( ( link, i ) => (
							<li key={ `${ link.url }-${ i }` }>
								{ shorten( link.url, 80 ) }
								{ link.anchor && (
									<span className="seoc-muted">
										{ ` — "${ shorten(
											link.anchor,
											50
										) }"` }
									</span>
								) }
							</li>
						) ) }
					</ul>

					<h3>
						{ sprintf(
							/* translators: %s: number of links */
							__(
								'Internal links on this page (%s)',
								'seo-console'
							),
							formatNumber( detail.data.outbound.length )
						) }
					</h3>
					<ul className="seoc-plain-list seoc-small">
						{ detail.data.outbound.map( ( link, i ) => (
							<li key={ `${ link.url }-${ i }` }>
								{ shorten( link.url, 80 ) }{ ' ' }
								{ link.status_code ? (
									<span className="seoc-tag">
										{ link.status_code }
									</span>
								) : null }
							</li>
						) ) }
					</ul>
				</>
			) }
		</Modal>
	);
}

/**
 * @param {Object} props
 * @param {number} props.runId Run id.
 * @return {JSX.Element} Tab.
 */
export default function PagesTab( { runId } ) {
	const [ filters, setFilters ] = useState( {
		indexability: '',
		status: '',
		has_issue: '',
		search: '',
		orderby: 'url',
		order: 'asc',
	} );
	const [ search, setSearch ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ detailId, setDetailId ] = useState( null );

	// Debounce the search box.
	useEffect( () => {
		const t = setTimeout( () => {
			setFilters( ( f ) => ( { ...f, search } ) );
			setPage( 1 );
		}, 400 );
		return () => clearTimeout( t );
	}, [ search ] );

	const list = useAsync(
		( signal ) =>
			getPages( runId, { ...filters, page, per_page: PER_PAGE }, signal ),
		[ runId, page, JSON.stringify( filters ) ]
	);
	const set = ( key ) => ( value ) => {
		setFilters( ( f ) => ( { ...f, [ key ]: value } ) );
		setPage( 1 );
	};
	const sortBy = ( key ) => {
		setFilters( ( f ) => ( {
			...f,
			orderby: key,
			order: f.orderby === key && f.order === 'asc' ? 'desc' : 'asc',
		} ) );
	};
	const sortHeader = ( key, label ) => (
		<th
			scope="col"
			aria-sort={
				filters.orderby === key ? `${ filters.order }ending` : undefined
			}
		>
			<Button variant="link" onClick={ () => sortBy( key ) }>
				{ label }
				{ filters.orderby === key &&
					( filters.order === 'asc' ? ' ▲' : ' ▼' ) }
			</Button>
		</th>
	);

	return (
		<div className="seoc-audit-pages">
			<div className="seoc-toolbar">
				<TextControl
					__nextHasNoMarginBottom
					label={ __( 'Search address or title', 'seo-console' ) }
					value={ search }
					onChange={ setSearch }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Indexing', 'seo-console' ) }
					value={ filters.indexability }
					options={ [
						{ value: '', label: __( 'All', 'seo-console' ) },
						...INDEXABILITY.map( ( v ) => ( {
							value: v,
							label: indexabilityLabel( v ),
						} ) ),
					] }
					onChange={ set( 'indexability' ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Status', 'seo-console' ) }
					value={ filters.status }
					options={ [
						{ value: '', label: __( 'All', 'seo-console' ) },
						{
							value: '2xx',
							label: __( 'OK (2xx)', 'seo-console' ),
						},
						{
							value: '3xx',
							label: __( 'Redirect (3xx)', 'seo-console' ),
						},
						{
							value: '4xx',
							label: __( 'Not found (4xx)', 'seo-console' ),
						},
						{
							value: '5xx',
							label: __( 'Server error (5xx)', 'seo-console' ),
						},
						{
							value: 'error',
							label: __( 'Failed to load', 'seo-console' ),
						},
					] }
					onChange={ set( 'status' ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Issues', 'seo-console' ) }
					value={ filters.has_issue }
					options={ [
						{ value: '', label: __( 'All', 'seo-console' ) },
						{
							value: '1',
							label: __( 'With issues', 'seo-console' ),
						},
						{
							value: '0',
							label: __( 'Without issues', 'seo-console' ),
						},
					] }
					onChange={ set( 'has_issue' ) }
				/>
			</div>
			{ list.error && (
				<ErrorNotice error={ list.error } onRetry={ list.reload } />
			) }
			{ ! list.data && ! list.error && <Loading /> }
			{ list.data && (
				<>
					<div className="seoc-table-scroll">
						<table className="widefat striped seoc-table">
							<caption className="screen-reader-text">
								{ __( 'Crawled pages', 'seo-console' ) }
							</caption>
							<thead>
								<tr>
									{ sortHeader(
										'url',
										__( 'Page', 'seo-console' )
									) }
									{ sortHeader(
										'status_code',
										__( 'Status', 'seo-console' )
									) }
									<th scope="col">
										{ __( 'Indexing', 'seo-console' ) }
									</th>
									<th scope="col">
										{ __( 'Title', 'seo-console' ) }
									</th>
									{ sortHeader(
										'word_count',
										__( 'Words', 'seo-console' )
									) }
									{ sortHeader(
										'depth',
										__( 'Clicks', 'seo-console' )
									) }
									{ sortHeader(
										'inbound_internal',
										__( 'Links in', 'seo-console' )
									) }
									{ sortHeader(
										'issues',
										__( 'Issues', 'seo-console' )
									) }
								</tr>
							</thead>
							<tbody>
								{ list.data.items.map( ( row ) => (
									<tr key={ row.id }>
										<td>
											<Button
												variant="link"
												onClick={ () =>
													setDetailId( row.id )
												}
											>
												{ shorten( row.url, 60 ) }
											</Button>
										</td>
										<td className="seoc-num">
											{ statusText( row ) }
										</td>
										<td>
											<StatusBadge
												status={ indexabilityStatus(
													row.indexability
												) }
												label={ indexabilityLabel(
													row.indexability
												) }
											/>
										</td>
										<td>
											{ shorten( row.title || '', 60 ) }
										</td>
										<td className="seoc-num">
											{ formatNumber( row.word_count ) }
										</td>
										<td className="seoc-num">
											{ row.depth === null
												? '—'
												: row.depth }
										</td>
										<td className="seoc-num">
											{ formatNumber(
												row.inbound_internal
											) }
										</td>
										<td className="seoc-num">
											{ formatNumber( row.issues ) }
										</td>
									</tr>
								) ) }
								{ ! list.data.items.length && (
									<tr>
										<td colSpan={ 8 }>
											{ __(
												'No pages match these filters.',
												'seo-console'
											) }
										</td>
									</tr>
								) }
							</tbody>
						</table>
					</div>
					<Pagination
						page={ page }
						perPage={ PER_PAGE }
						total={ list.data.total }
						onChange={ setPage }
						disabled={ list.loading }
					/>
				</>
			) }
			{ detailId && (
				<PageDetail
					pageId={ detailId }
					onClose={ () => setDetailId( null ) }
				/>
			) }
		</div>
	);
}
