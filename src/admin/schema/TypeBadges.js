/**
 * Small pills listing schema types (one per type), with a tooltip saying where each comes from.
 *
 * @param {Object} props
 * @param {Array}  props.items [ { type, label?, title?, variant?: 'seoc'|'pending' } ].
 * @param {Node}   props.empty Text when there are no items.
 * @return {JSX.Element} Badges.
 */
export default function TypeBadges( { items, empty } ) {
	if ( ! items.length ) {
		return <span className="seoc-muted">{ empty || '—' }</span>;
	}
	return (
		<span className="seoc-schema-badges">
			{ items.map( ( item ) => (
				<span
					key={ `${ item.type }-${ item.variant || '' }` }
					className={ `seoc-schema-badge${
						item.variant
							? ` seoc-schema-badge--${ item.variant }`
							: ''
					}` }
					title={ item.title || '' }
				>
					{ item.label || item.type }
				</span>
			) ) }
		</span>
	);
}
