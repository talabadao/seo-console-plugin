/**
 * The approval queue: loads GET /suggestions, lets a person edit, approve or reject each suggestion,
 * or several at once.
 */
import { Button, SelectControl } from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import {
	approveSuggestion,
	bulkSuggestions,
	normaliseError,
	getSuggestions,
	rejectSuggestion,
} from '../api';
import { getConfig } from '../config';
import { useAsync } from '../utils/hooks';
import { moduleLabel } from '../utils/format';
import ConfirmButton from './ConfirmButton';
import EmptyState from './EmptyState';
import { ErrorNotice, LiveRegion, Loading } from './Notices';
import Pagination from './Pagination';
import SuggestionRow, { ACTIONABLE } from './SuggestionRow';

export function suggestionStatusOptions() {
	return [
		{ value: 'pending', label: __( 'Waiting for review', 'seo-console' ) },
		{ value: 'stale', label: __( 'Out of date', 'seo-console' ) },
		{ value: 'applied', label: __( 'Applied', 'seo-console' ) },
		{ value: 'rejected', label: __( 'Rejected', 'seo-console' ) },
		{ value: 'failed', label: __( 'Failed', 'seo-console' ) },
		{ value: '', label: __( 'All', 'seo-console' ) },
	];
}

/**
 * @param {Object}   props
 * @param {Object}   props.query           Base query for GET /suggestions (e.g. { module: 'meta', object_id: 12 }).
 *                                         `status` and `module` here are the initial filter values;
 *                                         a `module` here also hides the module filter.
 * @param {string}   props.initialModule   Initial module filter that the person can still change.
 * @param {Function} props.onChanged       Called after anything was approved or rejected.
 * @param {boolean}  props.showFilters     Show status / module filters (default true).
 * @param {number}   props.perPage         Page size (default 20, max 100).
 * @param {Function} props.onFiltersChange Called with { status, module, page } (e.g. to sync the URL).
 * @param {Node}     props.emptyMessage    Text when the list is empty.
 * @return {JSX.Element} List.
 */
export default function SuggestionList( {
	query = {},
	initialModule = '',
	onChanged,
	showFilters = true,
	perPage = 20,
	onFiltersChange,
	emptyMessage,
} ) {
	const fixedModule = query.module || '';
	const [ status, setStatus ] = useState( query.status ?? 'pending' );
	const [ module, setModule ] = useState( fixedModule || initialModule );
	const [ page, setPage ] = useState(
		Math.max( 1, parseInt( query.page, 10 ) || 1 )
	);
	const [ edits, setEdits ] = useState( {} );
	const [ rowState, setRowState ] = useState( {} );
	const [ selected, setSelected ] = useState( [] );
	const [ bulkBusy, setBulkBusy ] = useState( false );
	const [ announce, setAnnounce ] = useState( '' );
	const [ bulkError, setBulkError ] = useState( null );

	const fullQuery = {
		...query,
		status,
		module: fixedModule || module,
		page,
		per_page: Math.min( 100, perPage ),
	};
	const queryKey = JSON.stringify( fullQuery );
	const list = useAsync(
		( signal ) => getSuggestions( fullQuery, signal ),
		[ queryKey ]
	);

	const items = useMemo(
		() => ( list.data ? list.data.items : [] ),
		[ list.data ]
	);

	const resetTransient = () => {
		setEdits( {} );
		setRowState( {} );
		setSelected( [] );
		setBulkError( null );
	};
	const changeFilters = ( next ) => {
		resetTransient();
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

	const editValueFor = ( item ) =>
		Object.prototype.hasOwnProperty.call( edits, item.id )
			? edits[ item.id ]
			: item.proposedValue ?? '';

	const isEdited = ( item ) =>
		Object.prototype.hasOwnProperty.call( edits, item.id ) &&
		typeof item.proposedValue !== 'object' &&
		edits[ item.id ] !== ( item.proposedValue ?? '' );

	const fieldName = ( item ) => item.fieldLabel || item.field;

	const approve = async ( item, force ) => {
		patchRow( item.id, { busy: true, error: null } );
		try {
			await approveSuggestion( item.id, {
				value: isEdited( item ) ? edits[ item.id ] : undefined,
				force,
			} );
			patchRow( item.id, { busy: false, done: 'applied' } );
			setSelected( ( s ) => s.filter( ( id ) => id !== item.id ) );
			setAnnounce(
				sprintf(
					/* translators: %s: field label */
					__( 'Applied: %s.', 'seo-console' ),
					fieldName( item )
				)
			);
			if ( onChanged ) {
				onChanged();
			}
		} catch ( e ) {
			const error = normaliseError( e );
			patchRow( item.id, { busy: false, error } );
			setAnnounce(
				error.code === 'seoc_health_failed'
					? __(
							'The change was rolled back automatically. Your site is as it was.',
							'seo-console'
					  )
					: error.message
			);
		}
	};

	const reject = async ( item ) => {
		patchRow( item.id, { busy: true, error: null } );
		try {
			await rejectSuggestion( item.id );
			patchRow( item.id, { busy: false, done: 'rejected' } );
			setSelected( ( s ) => s.filter( ( id ) => id !== item.id ) );
			setAnnounce(
				sprintf(
					/* translators: %s: field label */
					__( 'Rejected: %s.', 'seo-console' ),
					fieldName( item )
				)
			);
			if ( onChanged ) {
				onChanged();
			}
		} catch ( e ) {
			const error = normaliseError( e );
			patchRow( item.id, { busy: false, error } );
			setAnnounce( error.message );
		}
	};

	const selectable = items.filter(
		( i ) =>
			ACTIONABLE.includes( i.status ) && ! ( rowState[ i.id ] || {} ).done
	);
	const allSelected =
		selectable.length > 0 &&
		selectable.every( ( i ) => selected.includes( i.id ) );

	const runBulk = async ( action ) => {
		const chosen = items.filter( ( i ) => selected.includes( i.id ) );
		if ( ! chosen.length ) {
			return;
		}
		setBulkBusy( true );
		setBulkError( null );
		chosen.forEach( ( i ) =>
			patchRow( i.id, { busy: true, error: null } )
		);

		// Edited rows are approved one by one so the person's edit is what gets applied.
		const individually =
			action === 'approve' ? chosen.filter( isEdited ) : [];
		const inBulk = chosen.filter( ( i ) => ! individually.includes( i ) );
		let ok = 0;
		let failed = 0;

		for ( const item of individually ) {
			try {
				await approveSuggestion( item.id, { value: edits[ item.id ] } );
				patchRow( item.id, { busy: false, done: 'applied' } );
				ok++;
			} catch ( e ) {
				patchRow( item.id, {
					busy: false,
					error: normaliseError( e ),
				} );
				failed++;
			}
		}

		if ( inBulk.length ) {
			try {
				const results = await bulkSuggestions(
					action,
					inBulk.map( ( i ) => i.id )
				);
				inBulk.forEach( ( item ) => {
					const r = results.find( ( x ) => x.id === item.id );
					if ( r && r.ok ) {
						patchRow( item.id, {
							busy: false,
							done: action === 'approve' ? 'applied' : 'rejected',
						} );
						ok++;
					} else {
						patchRow( item.id, {
							busy: false,
							error: normaliseError(
								r
									? { code: r.code, message: r.message }
									: {
											message: __(
												'No result was returned for this item.',
												'seo-console'
											),
									  }
							),
						} );
						failed++;
					}
				} );
			} catch ( e ) {
				const error = normaliseError( e );
				setBulkError( error );
				inBulk.forEach( ( item ) =>
					patchRow( item.id, { busy: false } )
				);
				failed += inBulk.length;
			}
		}

		setSelected( [] );
		setBulkBusy( false );
		const doneWord =
			action === 'approve'
				? sprintf(
						/* translators: %d: number of suggestions */
						_n( '%d applied.', '%d applied.', ok, 'seo-console' ),
						ok
				  )
				: sprintf(
						/* translators: %d: number of suggestions */
						_n( '%d rejected.', '%d rejected.', ok, 'seo-console' ),
						ok
				  );
		const failWord = failed
			? ` ${ sprintf(
					/* translators: %d: number of suggestions */
					_n(
						'%d needs your attention (see below).',
						'%d need your attention (see below).',
						failed,
						'seo-console'
					),
					failed
			  ) }`
			: '';
		setAnnounce( doneWord + failWord );
		if ( ok && onChanged ) {
			onChanged();
		}
	};

	const moduleOptions = [
		{ value: '', label: __( 'All modules', 'seo-console' ) },
		...Object.keys( getConfig().modules ).map( ( id ) => ( {
			value: id,
			label: moduleLabel( id, getConfig().moduleInfo[ id ] ),
		} ) ),
	];

	return (
		<div className="seoc-suggestions">
			{ showFilters && (
				<div className="seoc-toolbar">
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Status', 'seo-console' ) }
						value={ status }
						options={ suggestionStatusOptions() }
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
						onClick={ () => {
							resetTransient();
							list.reload();
						} }
						disabled={ list.loading }
					>
						{ __( 'Refresh', 'seo-console' ) }
					</Button>
				</div>
			) }

			<LiveRegion visible>{ announce }</LiveRegion>

			{ list.error && (
				<ErrorNotice
					title={ __( 'Could not load suggestions.', 'seo-console' ) }
					error={ list.error }
					onRetry={ list.reload }
				/>
			) }
			{ bulkError && (
				<ErrorNotice
					title={ __(
						'The bulk action did not finish.',
						'seo-console'
					) }
					error={ bulkError }
				/>
			) }

			{ list.loading && ! list.data && (
				<Loading>
					{ __( 'Loading suggestions…', 'seo-console' ) }
				</Loading>
			) }

			{ ! list.loading && ! list.error && items.length === 0 && (
				<EmptyState
					icon="yes-alt"
					title={
						status === 'pending'
							? __( 'Nothing waiting for review', 'seo-console' )
							: __(
									'No suggestions match these filters',
									'seo-console'
							  )
					}
				>
					{ emptyMessage ||
						__(
							'Suggestions from AI and from audit rules appear here. Nothing is changed on your site until you approve it.',
							'seo-console'
						) }
				</EmptyState>
			) }

			{ items.length > 0 && selectable.length > 0 && (
				<div
					className="seoc-bulkbar"
					role="group"
					aria-label={ __( 'Bulk actions', 'seo-console' ) }
				>
					<label
						className="seoc-bulkbar__all"
						htmlFor="seoc-select-all"
					>
						<input
							id="seoc-select-all"
							type="checkbox"
							checked={ allSelected }
							onChange={ ( e ) =>
								setSelected(
									e.target.checked
										? selectable.map( ( i ) => i.id )
										: []
								)
							}
							disabled={ bulkBusy }
						/>{ ' ' }
						{ __( 'Select all on this page', 'seo-console' ) }
					</label>
					<span className="seoc-muted">
						{ sprintf(
							/* translators: %d: number of selected suggestions */
							_n(
								'%d selected',
								'%d selected',
								selected.length,
								'seo-console'
							),
							selected.length
						) }
					</span>
					<ConfirmButton
						variant="primary"
						size="compact"
						disabled={ ! selected.length || bulkBusy }
						isBusy={ bulkBusy }
						message={ sprintf(
							/* translators: %d: number of suggestions */
							_n(
								'Apply %d change to your site?',
								'Apply %d changes to your site?',
								selected.length,
								'seo-console'
							),
							selected.length
						) }
						confirmLabel={ __( 'Yes, apply', 'seo-console' ) }
						onConfirm={ () => runBulk( 'approve' ) }
					>
						{ __( 'Approve selected', 'seo-console' ) }
					</ConfirmButton>
					<ConfirmButton
						size="compact"
						disabled={ ! selected.length || bulkBusy }
						message={ __(
							'Reject the selected suggestions?',
							'seo-console'
						) }
						confirmLabel={ __( 'Yes, reject', 'seo-console' ) }
						onConfirm={ () => runBulk( 'reject' ) }
					>
						{ __( 'Reject selected', 'seo-console' ) }
					</ConfirmButton>
				</div>
			) }

			{ items.length > 0 && (
				<ul className="seoc-suggestion-list" aria-busy={ list.loading }>
					{ items.map( ( item ) => (
						<SuggestionRow
							key={ item.id }
							item={ item }
							editValue={ editValueFor( item ) }
							onEdit={ ( v ) =>
								setEdits( ( e ) => ( {
									...e,
									[ item.id ]: v,
								} ) )
							}
							state={ rowState[ item.id ] }
							selected={ selected.includes( item.id ) }
							onSelect={ ( on ) =>
								setSelected( ( s ) =>
									on
										? [ ...s, item.id ]
										: s.filter( ( id ) => id !== item.id )
								)
							}
							onApprove={ ( force ) => approve( item, force ) }
							onReject={ () => reject( item ) }
							showModule={ ! fixedModule }
						/>
					) ) }
				</ul>
			) }

			{ list.data && (
				<Pagination
					page={ page }
					perPage={ Math.min( 100, perPage ) }
					total={ list.data.total }
					disabled={ list.loading || bulkBusy }
					onChange={ ( p ) => changeFilters( { page: p } ) }
				/>
			) }
		</div>
	);
}
