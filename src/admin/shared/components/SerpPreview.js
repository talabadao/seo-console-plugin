/**
 * Approximate Google desktop result preview with truncation warnings.
 */
import { __, sprintf } from '@wordpress/i18n';

import { SERP, serpUrlParts, truncateToWidth } from '../utils/text';

/**
 * @param {Object} props
 * @param {string} props.title       Title as it would be output (omit to skip the title line).
 * @param {string} props.description Meta description (omit to skip).
 * @param {string} props.url         Page URL.
 * @param {string} props.label       Accessible label (default "Search result preview").
 * @return {JSX.Element} Preview.
 */
export default function SerpPreview( { title, description, url, label } ) {
	const t =
		title !== undefined && title !== null
			? truncateToWidth( title, SERP.title.maxPx, SERP.title.fontPx )
			: null;
	const d =
		description !== undefined && description !== null
			? truncateToWidth(
					description,
					SERP.description.maxPx,
					SERP.description.fontPx
			  )
			: null;
	const { host, path } = serpUrlParts( url );

	const warnings = [];
	if ( t && ! t.text ) {
		warnings.push(
			__(
				'The title is empty. Google will make one up from the page content.',
				'seo-console'
			)
		);
	} else if ( t && t.truncated ) {
		warnings.push(
			sprintf(
				/* translators: 1: estimated width in pixels, 2: available width in pixels */
				__(
					'The title is about %1$d px wide; Google shows about %2$d px on desktop, so the end will be cut off.',
					'seo-console'
				),
				t.width,
				t.maxPx
			)
		);
	}
	if ( d && ! d.text ) {
		warnings.push(
			__(
				'The description is empty. Google will pick text from the page instead.',
				'seo-console'
			)
		);
	} else if ( d && d.truncated ) {
		warnings.push(
			sprintf(
				/* translators: 1: estimated width in pixels, 2: available width in pixels */
				__(
					'The description is about %1$d px wide; Google shows about %2$d px on desktop, so the end will be cut off.',
					'seo-console'
				),
				d.width,
				d.maxPx
			)
		);
	}

	return (
		<figure
			className="seoc-serp"
			aria-label={ label || __( 'Search result preview', 'seo-console' ) }
		>
			<div className="seoc-serp__result">
				{ host && (
					<div className="seoc-serp__url">
						<span className="seoc-serp__host">{ host }</span>
						{ path && (
							<span className="seoc-serp__path">{ ` › ${ path }` }</span>
						) }
					</div>
				) }
				{ t && t.text && (
					<div className="seoc-serp__title">{ t.text }</div>
				) }
				{ d && d.text && (
					<div className="seoc-serp__description">{ d.text }</div>
				) }
			</div>
			<figcaption className="seoc-serp__caption">
				{ warnings.map( ( w ) => (
					<span key={ w } className="seoc-serp__warning">
						<span
							className="dashicons dashicons-warning"
							aria-hidden="true"
						/>
						{ w }
					</span>
				) ) }
				<span className="seoc-muted">
					{ __(
						'Approximate desktop preview. Google may rewrite titles and descriptions.',
						'seo-console'
					) }
				</span>
			</figcaption>
		</figure>
	);
}
