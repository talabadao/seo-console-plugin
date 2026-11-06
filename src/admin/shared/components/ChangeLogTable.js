/**
 * The change log: GET /changes with filters, before → after, and Undo (POST /changes/{id}/revert).
 */
import { Button, Notice, SelectControl } from '@wordpress/components';
import { Fragment, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { getChanges, normaliseError, revertChange } from '../api';
import { getConfig } from '../config';
import {
	formatDate,
	formatRelative,
	moduleLabel,
	sourceLabel,
} from '../utils/format';
import { useAsync } from '../utils/hooks';
import { humanise, shorten, valueToText } from '../utils/text';
import ConfirmButton from './ConfirmButton';
import EmptyState from './EmptyState';
import { ErrorNotice, LiveRegion, Loading } from './Notices';
import Pagination from './Pagination';
import StatusBadge from './StatusBadge';
import { ValueBox, objectName } from './SuggestionRow';

export function changeStatusOptions() {
	return [
		{ value: '', label: __( 'All', 'seo-console' ) },
		{ value: 'applied', label: __( 'Applied', 'seo-console' ) },
		{ value: 'reverted', label: __( 'Undone', 'seo-console' ) },
		{ value: 'failed', label: __( 'Failed', 'seo-console' ) },
	];
}

function ChangeStatus( { change } ) {
	if ( change.status === 'applied' ) {
		return (
			<StatusBadge
				status="good"
				label={ __( 'Applied', 'seo-console' ) }
			/>
		);
	}
	if ( change.status === 'reverted' ) {
		return (
			<>
				<StatusBadge
					status="neutral"
					label={ __( 'Undone', 'seo-console' ) }
				/>
				{ ( change.revertedAt || change.revertedByName ) && (
					<div
						className="seoc-muted seoc-small"
						title={ formatDate( change.revertedAt ) }
					>
						{ change.revertedByName
							? sprintf(
									/* translators: 1: user name, 2: relative time */
									__( 'by %1$s, %2$s', 'seo-console' ),
									change.revertedByName,
									formatRelative( change.revertedAt )
							  )
							: formatRelative( change.revertedAt ) }
					</div>
				) }
			</>
		);
	}
	if ( change.status === 'failed' ) {
		return (
			<>
				<StatusBadge
					status="poor"
					label={ __( 'Failed', 'seo-console' ) }
				/>
				{ change.error && (
					<div className="seoc-small">{ change.error }</div>
				) }
			</>
		);
	}
	return <StatusBadge status="unknown" label={ change.status } />;
}

function ValueCell( { before, after } ) {
	const b = valueToText( before );
	const a = valueToText( after );
	const long =
		b.length > 80 ||
		a.length > 80 ||
		typeof before === 'object' ||
		typeof after === 'object';
	const show = ( text ) =>
		text ? (
			shorten( text.replace( /\s+/g, ' ' ), 80 )
		) : (
			<span className="seoc-empty-value">
				{ __( '(empty)', 'seo-console' ) }
			</span>
		);
	return (
		<div className="seoc-diff">
			<div className="seoc-diff__before">
				<span className="screen-reader-text">
					{ __( 'Before:', 'seo-console' ) }{ ' ' }
				</span>
				{ show( b ) }
			</div>
			<div className="seoc-diff__after">
				<span aria-hidden="true">→ </span>
				<span className="screen-reader-text">
					{ __( 'After:', 'seo-console' ) }{ ' ' }
				</span>
				{ show( a ) }
			</div>
			{ long && (
				<details className="seoc-diff__full">
					<summary>
						{ __( 'Show full values', 'seo-console' ) }
					</summary>
					<p className="seoc-field__label">
						{ __( 'Before', 'seo-console' ) }
					</p>
					<ValueBox value={ before } />
					<p className="seoc-field__label">
						{ __( 'After', 'seo-console' ) }
					</p>
					<ValueBox value={ after } />
				</details>
			) }
		</div>
	);
}

/**
 * @param {Object}   props
 * @param {Object}   props.query           Base query (module, status, object_type, object_id). `module` here
 *                                         hides the module filter.
 * @param {string}   props.initialModule   Initial module filter that the person can still change.
 * @param {Function} props.onChanged       Called after an undo.
 * @param {boolean}  props.showFilters     Show filters (default true).
 * @param {number}   props.perPage         Page size (default 20).
 * @param {boolean}  props.compact         Dashboard mode: no filters, no pagination, fewer columns.
 * @param {Function} props.onFiltersChange Called with { status, module, page }.
 * @return {JSX.Element} Table.
 */
export default function ChangeLogTable( {
	query = {},
	initialModule = '',
	onChanged,
	showFilters = true,
	perPage = 20,
	compact = false,
	onFiltersChange,
} ) {
	const fixedModule = query.module || '';
	const [ status, setStatus ] = useState( query.status || '' );
	const [ module, setModule ] = useState( fixedModule || initialModule );
	const [ page, setPage ] = useState(
		Math.max( 1, parseInt( query.page, 10 ) || 1 )
	);
	const [ rowState, setRowState ] = useState( {} );
	const [ announce, setAnnounce ] = useState( '' );

	const fullQuery = {
		...query,
		status,
		module: fixedModule || module,
		page: compact ? 1 : page,
		per_page: Math.min( 100, perPage ),
	};
	const list = useAsync(
		( signal ) => getChanges( fullQuery, signal ),
		[ JSON.stringify( fullQuery ) ]
	);
	const items = list.data ? list.data.items : [];

	const changeFilters = ( next ) => {
		setRowState( {} );
		const merged = { status, module, page: 1, ...next };
		if ( 'status' in next ) {
			setStatus( next.status );
		}
		if ( 'module' in next ) {
			setModule( next.module );
		}
		setPage( merged.page );
		if ( onFiltersChange ) {
			onFiltersChange( merged );
		}
	};

	const patchRow = ( id, patch ) =>
		setRowState( ( s ) => ( {
			...s,
			[ id ]: { ...( s[ id ] || {} ), ...patch },
		} ) );

	const undo = async ( change, force = false ) => {
		patchRow( change.id, { busy: true, error: null } );
		try {
			await revertChange( change.id, { force } );
			patchRow( change.id, { busy: false } );
			setAnnounce(
				sprintf(
					/* translators: 1: field label, 2: object name */
					__( 'Undone: %1$s for %2$s.', 'seo-console' ),
					change.fieldLabel || humanise( change.field ),
					objectName( change )
				)
			);
			list.reload();
			if ( onChanged ) {
				onChanged();
			}
		} catch ( e ) {
			const error = normaliseError( e );
			patchRow( change.id, { busy: false, error } );
			setAnnounce( error.message );
		}
	};

	const moduleOptions = [
		{ value: '', label: __( 'All modules', 'seo-console' ) },
		...Object.keys( getConfig().modules ).map( ( id ) => ( {
			value: id,
			label: moduleLabel( id, getConfig().moduleInfo[ id ] ),
		} ) ),
	];
	const columns = compact ? 5 : 6;

	return (
		<div className="seoc-changes">
			{ showFilters && ! compact && (
				<div className="seoc-toolbar">
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Status', 'seo-console' ) }
						value={ status }
						options={ changeStatusOptions() }
						onChange={ ( v ) => changeFilters( { status: v } ) }
					/>
					{ ! fixedModule && (
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ __( 'Module', 'seo-console' ) }
							value={ module }
							options={ moduleOptions }
							onChange={ ( v ) => changeFilters( { module: v } ) }
						/>
					) }
					<Button
						variant="tertiary"
						onClick={ list.reload }
						disabled={ list.loading }
					>
						{ __( 'Refresh', 'seo-console' ) }
					</Button>
				</div>
			) }

			<LiveRegion visible={ ! compact }>{ announce }</LiveRegion>

			{ list.error && (
				<ErrorNotice
					title={ __(
						'Could not load the change log.',
						'seo-console'
					) }
					error={ list.error }
					onRetry={ list.reload }
				/>
			) }

			{ list.loading && ! list.data && (
				<Loading>{ __( 'Loading changes…', 'seo-console' ) }</Loading>
			) }

			{ ! list.loading && ! list.error && items.length === 0 && (
				<EmptyState
					icon="backup"
					title={ __( 'No changes yet', 'seo-console' ) }
				>
					{ __(
						'Every change SEO Console makes to your site is listed here, with who approved it and a button to undo it.',
						'seo-console'
					) }
				</EmptyState>
			) }

			{ items.length > 0 && (
				<div className="seoc-table-scroll">
					<table
						className="widefat striped seoc-table"
						aria-busy={ list.loading }
					>
						<caption className="screen-reader-text">
							{ __( 'Change log', 'seo-console' ) }
						</caption>
						<thead>
							<tr>
								<th scope="col">
									{ __( 'When', 'seo-console' ) }
								</th>
								{ ! compact && (
									<th scope="col">
										{ __( 'Who', 'seo-console' ) }
									</th>
								) }
								<th scope="col">
									{ __( 'What', 'seo-console' ) }
								</th>
								<th scope="col">
									{ __( 'Before → after', 'seo-console' ) }
								</th>
								<th scope="col">
									{ __( 'Status', 'seo-console' ) }
								</th>
								<th scope="col">
									<span className="screen-reader-text">
										{ __( 'Actions', 'seo-console' ) }
									</span>
								</th>
							</tr>
						</thead>
						<tbody>
							{ items.map( ( c ) => {
								const st = rowState[ c.id ] || {};
								const conflict =
									st.error &&
									st.error.code === 'seoc_conflict';
								return (
									<Fragment key={ c.id }>
										<tr>
											<td>
												<span
													title={ formatDate(
														c.createdAt
													) }
												>
													{ formatRelative(
														c.createdAt
													) }
												</span>
											</td>
											{ ! compact && (
												<td>
													{ c.userName ||
														( c.userId
															? `#${ c.userId }`
															: '—' ) }
													<div className="seoc-muted seoc-small">
														{ sourceLabel(
															c.source
														) }
													</div>
												</td>
											) }
											<td>
												<strong>
													{ c.fieldLabel ||
														humanise( c.field ) }
												</strong>
												<div>
													{ c.editLink ? (
														<a href={ c.editLink }>
															{ objectName( c ) }
														</a>
													) : (
														objectName( c )
													) }
												</div>
												{ c.module && (
													<span className="seoc-tag">
														{ moduleLabel(
															c.module
														) }
													</span>
												) }
												{ c.note && (
													<div className="seoc-muted seoc-small">
														{ c.note }
													</div>
												) }
											</td>
											<td>
												<ValueCell
													before={ c.before }
													after={ c.after }
												/>
											</td>
											<td>
												<ChangeStatus change={ c } />
											</td>
											<td className="seoc-table__actions">
												{ c.status === 'applied' &&
													! conflict && (
														<ConfirmButton
															size="compact"
															isBusy={ st.busy }
															message={ __(
																'Put the old value back?',
																'seo-console'
															) }
															confirmLabel={ __(
																'Yes, undo',
																'seo-console'
															) }
															onConfirm={ () =>
																undo( c )
															}
														>
															{ __(
																'Undo',
																'seo-console'
															) }
														</ConfirmButton>
													) }
											</td>
										</tr>
										{ st.error && (
											<tr className="seoc-table__message">
												<td colSpan={ columns }>
													{ conflict ? (
														<Notice
															status="warning"
															isDismissible={
																false
															}
														>
															<p>
																<strong>
																	{ __(
																		'This value has changed since.',
																		'seo-console'
																	) }
																</strong>{ ' ' }
																{ __(
																	'Someone or something edited it after SEO Console did. Undoing now will overwrite that newer edit with the old value.',
																	'seo-console'
																) }
															</p>
															{ st.error.data &&
																st.error.data
																	.current_value !==
																	undefined && (
																	<div className="seoc-field">
																		<p className="seoc-field__label">
																			{ __(
																				'On the site now',
																				'seo-console'
																			) }
																		</p>
																		<ValueBox
																			value={
																				st
																					.error
																					.data
																					.current_value
																			}
																		/>
																	</div>
																) }
															<p>
																<Button
																	variant="secondary"
																	isDestructive
																	isBusy={
																		st.busy
																	}
																	disabled={
																		st.busy
																	}
																	onClick={ () =>
																		undo(
																			c,
																			true
																		)
																	}
																>
																	{ __(
																		'Undo anyway',
																		'seo-console'
																	) }
																</Button>{ ' ' }
																<Button
																	variant="tertiary"
																	onClick={ () =>
																		patchRow(
																			c.id,
																			{
																				error: null,
																			}
																		)
																	}
																>
																	{ __(
																		'Keep the newer value',
																		'seo-console'
																	) }
																</Button>
															</p>
														</Notice>
													) : (
														<Notice
															status="error"
															isDismissible={
																false
															}
														>
															{ st.error.code ===
															'seoc_health_failed'
																? __(
																		'Undoing this made your site stop responding normally, so SEO Console put the change back. Nothing is different from before you clicked Undo.',
																		'seo-console'
																  )
																: st.error
																		.message }
														</Notice>
													) }
												</td>
											</tr>
										) }
									</Fragment>
								);
							} ) }
						</tbody>
					</table>
				</div>
			) }

			{ ! compact && list.data && (
				<Pagination
					page={ page }
					perPage={ Math.min( 100, perPage ) }
					total={ list.data.total }
					disabled={ list.loading }
					onChange={ ( p ) => changeFilters( { page: p } ) }
				/>
			) }
		</div>
	);
}
