/**
 * Previous / next pagination with a "Showing 21–40 of 134" summary.
 */
import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { formatNumber } from '../utils/format';

/**
 * @param {Object}   props
 * @param {number}   props.page     Current page (1-based).
 * @param {number}   props.perPage  Items per page.
 * @param {number}   props.total    Total items.
 * @param {Function} props.onChange Called with the new page.
 * @param {boolean}  props.disabled Disable while loading.
 * @return {JSX.Element|null} Pagination (null when there is nothing to show).
 */
export default function Pagination( {
	page = 1,
	perPage = 20,
	total = 0,
	onChange,
	disabled = false,
} ) {
	if ( ! total ) {
		return null;
	}
	const pages = Math.max( 1, Math.ceil( total / perPage ) );
	const from = ( page - 1 ) * perPage + 1;
	const to = Math.min( total, page * perPage );
	return (
		<nav
			className="seoc-pagination"
			aria-label={ __( 'Pagination', 'seo-console' ) }
		>
			<span className="seoc-pagination__summary">
				{ sprintf(
					/* translators: 1: first item number, 2: last item number, 3: total */
					__( 'Showing %1$s–%2$s of %3$s', 'seo-console' ),
					formatNumber( from ),
					formatNumber( to ),
					formatNumber( total )
				) }
			</span>
			{ pages > 1 && (
				<span className="seoc-pagination__buttons">
					<Button
						variant="secondary"
						size="compact"
						disabled={ disabled || page <= 1 }
						onClick={ () => onChange( page - 1 ) }
					>
						{ __( 'Previous', 'seo-console' ) }
					</Button>
					<span className="seoc-pagination__page">
						{ sprintf(
							/* translators: 1: current page, 2: number of pages */
							__( 'Page %1$s of %2$s', 'seo-console' ),
							formatNumber( page ),
							formatNumber( pages )
						) }
					</span>
					<Button
						variant="secondary"
						size="compact"
						disabled={ disabled || page >= pages }
						onClick={ () => onChange( page + 1 ) }
					>
						{ __( 'Next', 'seo-console' ) }
					</Button>
				</span>
			) }
		</nav>
	);
}
