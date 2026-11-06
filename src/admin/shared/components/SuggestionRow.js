/**
 * One suggestion: current ↔ proposed, editable value, counters, SERP preview, approve / reject,
 * plus the stale and health-check outcomes.
 */
import {
	Button,
	ExternalLink,
	Notice,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { adminPageUrl } from '../config';
import {
	formatDate,
	formatRelative,
	moduleLabel,
	sourceWithModel,
} from '../utils/format';
import { fieldKind, humanise, valueToText } from '../utils/text';
import CharCounter from './CharCounter';
import SerpPreview from './SerpPreview';
import StatusBadge from './StatusBadge';

export const ACTIONABLE = [ 'pending', 'stale' ];

export function suggestionStatusBadge( status ) {
	switch ( status ) {
		case 'pending':
			return (
				<StatusBadge
					status="info"
					label={ __( 'Waiting for review', 'seo-console' ) }
				/>
			);
		case 'applied':
			return (
				<StatusBadge
					status="good"
					label={ __( 'Applied', 'seo-console' ) }
				/>
			);
		case 'rejected':
			return (
				<StatusBadge
					status="neutral"
					label={ __( 'Rejected', 'seo-console' ) }
				/>
			);
		case 'failed':
			return (
				<StatusBadge
					status="poor"
					label={ __( 'Failed', 'seo-console' ) }
				/>
			);
		case 'stale':
			return (
				<StatusBadge
					status="needs-work"
					label={ __( 'Out of date', 'seo-console' ) }
				/>
			);
		default:
			return <StatusBadge status="unknown" label={ status } />;
	}
}

/**
 * Read-only value box ("(empty)" for nothing, JSON for structured values).
 *
 * @param {Object} props
 * @param {*}      props.value Value.
 * @return {JSX.Element} Box.
 */
export function ValueBox( { value } ) {
	const text = valueToText( value );
	if ( ! text ) {
		return (
			<span className="seoc-empty-value">
				{ __( '(empty)', 'seo-console' ) }
			</span>
		);
	}
	if ( typeof value === 'object' ) {
		return <pre className="seoc-json">{ text }</pre>;
	}
	return <span className="seoc-value-text">{ text }</span>;
}

export function objectName( item ) {
	if ( item.objectTitle ) {
		return item.objectTitle;
	}
	if ( item.objectType === 'site' || ! item.objectType ) {
		return __( 'Whole site', 'seo-console' );
	}
	if ( item.objectType === 'option' ) {
		return __( 'Site setting', 'seo-console' );
	}
	return item.objectId
		? sprintf(
				/* translators: 1: object type (post, term…), 2: id */
				__( '%1$s #%2$d', 'seo-console' ),
				humanise( item.objectType ),
				item.objectId
		  )
		: humanise( item.objectType );
}

/**
 * @param {Object}   props
 * @param {Object}   props.item       Normalised suggestion.
 * @param {*}        props.editValue  Current value in the editor.
 * @param {Function} props.onEdit     ( value ) => void.
 * @param {Object}   props.state      { busy, error, done: 'applied'|'rejected', message }.
 * @param {boolean}  props.selected   Checkbox state.
 * @param {Function} props.onSelect   ( bool ) => void.
 * @param {Function} props.onApprove  ( force: bool ) => void.
 * @param {Function} props.onReject   () => void.
 * @param {boolean}  props.showModule Show the module name.
 * @return {JSX.Element} Row.
 */
export default function SuggestionRow( {
	item,
	editValue,
	onEdit,
	state = {},
	selected,
	onSelect,
	onApprove,
	onReject,
	showModule = true,
} ) {
	const field = item.fieldLabel || humanise( item.field );
	const name = objectName( item );
	const kind = fieldKind( item.field );
	const isString =
		typeof item.proposedValue === 'string' || item.proposedValue === null;
	const actionable = ACTIONABLE.includes( item.status ) && ! state.done;
	const edited =
		isString && ( editValue ?? '' ) !== ( item.proposedValue ?? '' );
	const inputId = `seoc-sugg-${ item.id }`;
	const counterId = `${ inputId }-count`;
	const err = state.error;
	const isStale =
		( err && err.code === 'seoc_stale' ) ||
		( item.status === 'stale' && ! err );
	const liveValue =
		err && err.data && err.data.current_value !== undefined
			? err.data.current_value
			: item.currentValue;

	const textProps = {
		__nextHasNoMarginBottom: true,
		id: inputId,
		label: __( 'Value to apply (you can edit it)', 'seo-console' ),
		value: editValue ?? '',
		onChange: onEdit,
		disabled: ! actionable || !! state.busy,
		'aria-describedby': kind ? counterId : undefined,
	};
	let editor;
	if ( ! isString ) {
		editor = (
			<div className="seoc-field">
				<p className="seoc-field__label">
					{ __( 'Value to apply', 'seo-console' ) }
				</p>
				<pre className="seoc-json">
					{ valueToText( item.proposedValue ) }
				</pre>
				<p className="seoc-muted seoc-small">
					{ __(
						'This is structured data. It is applied exactly as shown.',
						'seo-console'
					) }
				</p>
			</div>
		);
	} else if (
		kind === 'description' ||
		( item.proposedValue || '' ).length > 90
	) {
		editor = <TextareaControl { ...textProps } rows={ 3 } />;
	} else {
		editor = <TextControl { ...textProps } __next40pxDefaultSize />;
	}

	return (
		<li
			className={ `seoc-suggestion${ selected ? ' is-selected' : '' }${
				state.done ? ' is-done' : ''
			}` }
			aria-labelledby={ `${ inputId }-heading` }
		>
			<div className="seoc-suggestion__head">
				{ actionable && (
					<span className="seoc-suggestion__check">
						<input
							type="checkbox"
							id={ `${ inputId }-select` }
							checked={ !! selected }
							onChange={ ( e ) => onSelect( e.target.checked ) }
							disabled={ state.busy }
						/>
						<label
							htmlFor={ `${ inputId }-select` }
							className="screen-reader-text"
						>
							{ sprintf(
								/* translators: 1: field label, 2: page or object name */
								__( 'Select: %1$s for %2$s', 'seo-console' ),
								field,
								name
							) }
						</label>
					</span>
				) }
				<div className="seoc-suggestion__title">
					<h3 id={ `${ inputId }-heading` }>
						<span className="seoc-suggestion__field">
							{ field }
						</span>
						<span className="seoc-muted"> · </span>
						<span>{ name }</span>
					</h3>
					<div className="seoc-suggestion__links">
						{ item.editLink && (
							<a href={ item.editLink }>
								{ __( 'Edit', 'seo-console' ) }
							</a>
						) }
						{ item.viewLink && (
							<ExternalLink href={ item.viewLink }>
								{ __( 'View', 'seo-console' ) }
							</ExternalLink>
						) }
						{ showModule && item.module && (
							<span className="seoc-tag">
								{ moduleLabel( item.module ) }
							</span>
						) }
						<span
							className="seoc-muted"
							title={ formatDate( item.createdAt ) }
						>
							{ formatRelative( item.createdAt ) }
						</span>
					</div>
				</div>
				<div className="seoc-suggestion__status">
					{ suggestionStatusBadge( state.done || item.status ) }
				</div>
			</div>

			<div className="seoc-compare">
				<div className="seoc-compare__col">
					<p className="seoc-compare__label">
						{ __( 'Current', 'seo-console' ) }
					</p>
					<ValueBox value={ item.currentValue } />
				</div>
				<div className="seoc-compare__arrow" aria-hidden="true">
					→
				</div>
				<div className="seoc-compare__col">
					<p className="seoc-compare__label">
						{ __( 'Proposed', 'seo-console' ) }
					</p>
					<ValueBox value={ item.proposedValue } />
				</div>
			</div>

			{ item.rationale && (
				<p className="seoc-suggestion__rationale">
					<strong>{ __( 'Why:', 'seo-console' ) }</strong>{ ' ' }
					{ item.rationale }
				</p>
			) }
			<p className="seoc-muted seoc-small">
				{ sprintf(
					/* translators: %s: source and model, e.g. "AI · claude-opus-5-5" */
					__( 'Suggested by %s', 'seo-console' ),
					sourceWithModel( item.source, item.model )
				) }
				{ item.confidence !== null &&
					` · ${ sprintf(
						/* translators: %d: confidence percentage */
						__( 'confidence %d%%', 'seo-console' ),
						Math.round( item.confidence * 100 )
					) }` }
			</p>

			{ ( actionable || state.done === 'applied' ) && (
				<div className="seoc-suggestion__editor">
					{ editor }
					{ edited && actionable && (
						<p className="seoc-small">
							{ __(
								'Edited. Your version will be applied.',
								'seo-console'
							) }{ ' ' }
							<Button
								variant="link"
								onClick={ () =>
									onEdit( item.proposedValue ?? '' )
								}
							>
								{ __( 'Reset to the proposal', 'seo-console' ) }
							</Button>
						</p>
					) }
					{ kind && isString && (
						<>
							<CharCounter
								id={ counterId }
								kind={ kind }
								value={ editValue ?? '' }
							/>
							<SerpPreview
								url={ item.viewLink }
								title={
									kind === 'title'
										? editValue ?? ''
										: undefined
								}
								description={
									kind === 'description'
										? editValue ?? ''
										: undefined
								}
							/>
						</>
					) }
				</div>
			) }

			{ item.status === 'failed' && item.error && ! state.done && (
				<Notice status="error" isDismissible={ false }>
					{ __(
						'This suggestion could not be applied:',
						'seo-console'
					) }{ ' ' }
					{ item.error }
				</Notice>
			) }

			{ isStale && actionable && (
				<Notice status="warning" isDismissible={ false }>
					<p>
						<strong>
							{ __(
								'The value on the site has changed.',
								'seo-console'
							) }
						</strong>{ ' ' }
						{ __(
							'Someone (or another plugin) changed it after this suggestion was made. Applying it will replace what is there now.',
							'seo-console'
						) }
					</p>
					<div className="seoc-field">
						<p className="seoc-field__label">
							{ __( 'On the site now', 'seo-console' ) }
						</p>
						<ValueBox value={ liveValue } />
					</div>
					<p>
						<Button
							variant="secondary"
							onClick={ () => onApprove( true ) }
							isBusy={ state.busy }
							disabled={ state.busy }
						>
							{ __( 'Apply anyway', 'seo-console' ) }
						</Button>{ ' ' }
						<Button
							variant="tertiary"
							onClick={ onReject }
							disabled={ state.busy }
						>
							{ __( 'Reject', 'seo-console' ) }
						</Button>
					</p>
				</Notice>
			) }

			{ err && err.code === 'seoc_health_failed' && (
				<Notice status="error" isDismissible={ false }>
					<p>
						<strong>
							{ __(
								'The change was rolled back automatically.',
								'seo-console'
							) }
						</strong>{ ' ' }
						{ __(
							'SEO Console applied it, checked your site straight away, and the site did not respond normally. So it undid the change. Your site is as it was before.',
							'seo-console'
						) }
					</p>
					{ err.message && (
						<p className="seoc-small">
							{ __( 'Details:', 'seo-console' ) } { err.message }
						</p>
					) }
				</Notice>
			) }

			{ err &&
				! [ 'seoc_stale', 'seoc_health_failed' ].includes(
					err.code
				) && (
					<Notice status="error" isDismissible={ false }>
						{ err.message }
					</Notice>
				) }

			{ state.done === 'applied' && (
				<p className="seoc-outcome seoc-outcome--good">
					<span
						className="dashicons dashicons-yes-alt"
						aria-hidden="true"
					/>
					{ __(
						'Applied. You can undo it from the change log.',
						'seo-console'
					) }{ ' ' }
					<a href={ adminPageUrl( 'changes' ) }>
						{ __( 'Open change log', 'seo-console' ) }
					</a>
				</p>
			) }
			{ state.done === 'rejected' && (
				<p className="seoc-outcome">
					<span
						className="dashicons dashicons-dismiss"
						aria-hidden="true"
					/>
					{ __(
						'Rejected. Nothing was changed on the site.',
						'seo-console'
					) }
				</p>
			) }

			{ actionable && ! isStale && (
				<div className="seoc-suggestion__actions">
					<Button
						variant="primary"
						onClick={ () => onApprove( false ) }
						isBusy={ state.busy }
						disabled={
							!! state.busy ||
							( isString &&
								!! kind &&
								! ( editValue ?? '' ).trim() )
						}
					>
						{ edited
							? __( 'Approve my edit', 'seo-console' )
							: __( 'Approve', 'seo-console' ) }
					</Button>
					<Button
						variant="secondary"
						onClick={ onReject }
						disabled={ state.busy }
					>
						{ __( 'Reject', 'seo-console' ) }
					</Button>
				</div>
			) }
		</li>
	);
}
