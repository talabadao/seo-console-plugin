/**
 * Calm empty state: what is missing, why, and what to do next.
 */

/**
 * @param {Object} props
 * @param {Node}   props.title    Short statement ("No suggestions waiting").
 * @param {Node}   props.children Explanation (one or two sentences).
 * @param {Node}   props.actions  Buttons or links.
 * @param {string} props.icon     Dashicon name without prefix (default "info-outline").
 * @return {JSX.Element} Empty state.
 */
export default function EmptyState( {
	title,
	children,
	actions,
	icon = 'info-outline',
} ) {
	return (
		<div className="seoc-empty">
			<span
				className={ `seoc-empty__icon dashicons dashicons-${ icon }` }
				aria-hidden="true"
			/>
			{ title && <p className="seoc-empty__title">{ title }</p> }
			{ children && <div className="seoc-empty__text">{ children }</div> }
			{ actions && (
				<div className="seoc-empty__actions">{ actions }</div>
			) }
		</div>
	);
}
