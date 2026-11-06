/**
 * Notice helpers: a notice list for page-level messages, an error notice for normalised API errors,
 * a loading line, and an aria-live region for async results.
 */
import { Button, Notice, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Render notices from useNotices().
 *
 * @param {Object}   props
 * @param {Array}    props.notices  [ { id, status, message, isDismissible, actions } ].
 * @param {Function} props.onRemove Called with the notice id.
 * @return {JSX.Element|null} List.
 */
export function NoticeList( { notices = [], onRemove } ) {
	if ( ! notices.length ) {
		return null;
	}
	return (
		<div className="seoc-notices">
			{ notices.map( ( n ) => (
				<Notice
					key={ n.id }
					status={ n.status }
					isDismissible={ n.isDismissible !== false && !! onRemove }
					onRemove={ () => onRemove && onRemove( n.id ) }
					actions={ n.actions }
				>
					{ n.message }
				</Notice>
			) ) }
		</div>
	);
}

/**
 * Show a normalised API error ({ code, message }) with an optional retry.
 *
 * @param {Object}   props
 * @param {Object}   props.error   Normalised error.
 * @param {Function} props.onRetry Retry callback.
 * @param {Node}     props.title   Lead sentence ("Could not load suggestions.").
 * @return {JSX.Element|null} Notice.
 */
export function ErrorNotice( { error, onRetry, title } ) {
	if ( ! error ) {
		return null;
	}
	return (
		<Notice status="error" isDismissible={ false }>
			<p>
				{ title && <strong>{ title } </strong> }
				{ error.message }
			</p>
			{ error.code && error.code !== 'seoc_unknown' && (
				<p className="seoc-muted seoc-small">
					{ __( 'Error code:', 'seo-console' ) }{ ' ' }
					<code>{ error.code }</code>
				</p>
			) }
			{ onRetry && (
				<p>
					<Button variant="secondary" onClick={ onRetry }>
						{ __( 'Try again', 'seo-console' ) }
					</Button>
				</p>
			) }
		</Notice>
	);
}

/**
 * Inline loading line ("Loading suggestions…") — announced politely.
 *
 * @param {Object} props
 * @param {Node}   props.children Label.
 * @return {JSX.Element} Loading line.
 */
export function Loading( { children } ) {
	return (
		<div className="seoc-loading" role="status">
			<Spinner />
			<span>{ children || __( 'Loading…', 'seo-console' ) }</span>
		</div>
	);
}

/**
 * Visually hidden (by default) polite live region for async results.
 *
 * @param {Object}  props
 * @param {Node}    props.children Message.
 * @param {boolean} props.visible  Show the text.
 * @return {JSX.Element} Region.
 */
export function LiveRegion( { children, visible = false } ) {
	return (
		<div
			className={ visible ? 'seoc-live' : 'screen-reader-text' }
			aria-live="polite"
			aria-atomic="true"
		>
			{ children }
		</div>
	);
}
