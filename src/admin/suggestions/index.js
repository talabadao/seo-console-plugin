/**
 * Suggestions page: the approval queue for every module.
 */
import { __ } from '@wordpress/i18n';

import {
	Page,
	SuggestionList,
	adminPageUrl,
	getUrlParams,
	mount,
	setUrlParams,
} from '../shared';

function App() {
	const params = getUrlParams();
	let status = params.has( 'status' ) ? params.get( 'status' ) : 'pending';
	if ( status === 'all' ) {
		status = '';
	}
	const query = {
		status,
		page: params.get( 'paged' ) || 1,
	};
	const initialModule = params.get( 'module' ) || '';

	return (
		<Page
			title={ __( 'Suggestions', 'seo-console' ) }
			description={ __(
				'Changes proposed by AI and by audit rules. Check each one, edit it if you like, then approve or reject it. Nothing changes on your site until you approve.',
				'seo-console'
			) }
			meta={
				<>
					{ __(
						'Every approved change is logged and can be undone.',
						'seo-console'
					) }{ ' ' }
					<a href={ adminPageUrl( 'changes' ) }>
						{ __( 'Open the change log', 'seo-console' ) }
					</a>
				</>
			}
		>
			<SuggestionList
				query={ query }
				initialModule={ initialModule }
				onFiltersChange={ ( f ) =>
					setUrlParams( {
						status: f.status === 'pending' ? '' : f.status || 'all',
						module: f.module,
						paged: f.page > 1 ? f.page : '',
					} )
				}
			/>
		</Page>
	);
}

mount( App );
