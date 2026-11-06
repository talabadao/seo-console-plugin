/**
 * About rich results: what each type can (and cannot) do in Google, in plain English.
 */
import { __ } from '@wordpress/i18n';

import { Card, ErrorNotice, Loading, StatusBadge, useAsync } from '../shared';
import { getTypes } from './api';

const RICH = {
	eligible: () => ( {
		status: 'good',
		label: __( 'Rich result possible', 'seo-console' ),
	} ),
	limited: () => ( {
		status: 'needs-work',
		label: __( 'Rich result only for some sites', 'seo-console' ),
	} ),
	removed: () => ( {
		status: 'neutral',
		label: __( 'Rich result retired by Google', 'seo-console' ),
	} ),
	none: () => ( {
		status: 'neutral',
		label: __( 'No rich result', 'seo-console' ),
	} ),
};

export default function AboutTab() {
	const types = useAsync( ( signal ) => getTypes( signal ), [] );

	return (
		<>
			<Card title={ __( 'What structured data does', 'seo-console' ) }>
				<p>
					{ __(
						'Structured data describes a page in a format machines read reliably. Search engines and AI assistants use it to understand your content. Some types can also make your result look richer in Google (stars, prices, dates), but Google decides when to show them, and valid markup is never a guarantee.',
						'seo-console'
					) }
				</p>
				<p>
					{ __(
						'SEO Console only adds facts that are visible on your page, never invents ratings or reviews, and never duplicates what your SEO plugin already outputs.',
						'seo-console'
					) }
				</p>
			</Card>
			{ types.loading && ! types.data && (
				<Loading>{ __( 'Loading…', 'seo-console' ) }</Loading>
			) }
			{ types.error && (
				<ErrorNotice error={ types.error } onRetry={ types.reload } />
			) }
			{ types.data && (
				<div className="seoc-grid">
					{ types.data.items.map( ( item ) => {
						const rich = (
							RICH[ item.rich_result ] || RICH.none
						)();
						return (
							<Card
								key={ item.type }
								title={ item.label }
								headingLevel={ 3 }
								actions={
									<StatusBadge
										status={ rich.status }
										label={ rich.label }
									/>
								}
							>
								<p>{ item.note }</p>
								{ item.required.length > 0 && (
									<p className="seoc-muted seoc-small">
										{ __( 'Needs:', 'seo-console' ) }{ ' ' }
										{ item.required.join( ', ' ) }
									</p>
								) }
							</Card>
						);
					} ) }
				</div>
			) }
		</>
	);
}
