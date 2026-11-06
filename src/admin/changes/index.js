/**
 * Change log page: everything SEO Console changed on the site, with Undo.
 */
import { __, sprintf } from '@wordpress/i18n';

import {
	ChangeLogTable,
	Page,
	getUrlParams,
	mount,
	setUrlParams,
	siteTimezoneLabel,
} from '../shared';

function App() {
	const params = getUrlParams();
	const query = {
		status: params.get( 'status' ) || '',
		page: params.get( 'paged' ) || 1,
	};
	return (
		<Page
			title={ __( 'Change log', 'seo-console' ) }
			description={ __(
				'Every change SEO Console made to your site: who approved it, what it was before and after. Undo puts the old value back.',
				'seo-console'
			) }
			meta={ sprintf(
				/* translators: %s: time zone */
				__(
					'Times are shown in the site time zone (%s). Hover a time to see the exact date.',
					'seo-console'
				),
				siteTimezoneLabel()
			) }
		>
			<ChangeLogTable
				query={ query }
				initialModule={ params.get( 'module' ) || '' }
				onFiltersChange={ ( f ) =>
					setUrlParams( {
						status: f.status,
						module: f.module,
						paged: f.page > 1 ? f.page : '',
					} )
				}
			/>
		</Page>
	);
}

mount( App );
