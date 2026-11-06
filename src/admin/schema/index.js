/**
 * Structured data page: posts (detected vs SEO Console schema, suggest, needs input, preview),
 * business info (site-wide Organization / LocalBusiness) and an honest note about rich results.
 */
import { __, sprintf } from '@wordpress/i18n';

import {
	Page,
	TabPanelRoute,
	adminPageUrl,
	getConfig,
	mount,
	useNotices,
} from '../shared';
import AboutTab from './AboutTab';
import BusinessTab from './BusinessTab';
import PostsTab from './PostsTab';
import { seoPluginName } from './api';
import './style.scss';

function App() {
	const { notices, addNotice, removeNotice } = useNotices();
	const plugin = seoPluginName( getConfig().seoPlugin );
	const tabs = [
		{ name: 'posts', title: __( 'Posts', 'seo-console' ) },
		{ name: 'business', title: __( 'Business info', 'seo-console' ) },
		{ name: 'about', title: __( 'About rich results', 'seo-console' ) },
	];

	return (
		<Page
			title={ __( 'Structured data', 'seo-console' ) }
			description={ __(
				'Structured data (schema) tells search engines and AI assistants what a page is about: its FAQs, steps, videos, products or your business details. SEO Console finds it in your content, you approve it, and it is added to your pages.',
				'seo-console'
			) }
			meta={
				<>
					{ plugin
						? sprintf(
								/* translators: %s: SEO plugin name */
								__(
									'Added inside the %s schema, never as a duplicate.',
									'seo-console'
								),
								plugin
						  )
						: __(
								'No SEO plugin found: SEO Console adds its own structured data block.',
								'seo-console'
						  ) }{ ' ' }
					<a
						href={ adminPageUrl( 'suggestions', {
							module: 'schema',
						} ) }
					>
						{ __(
							'See all structured data suggestions',
							'seo-console'
						) }
					</a>
				</>
			}
			notices={ notices }
			onRemoveNotice={ removeNotice }
		>
			<TabPanelRoute tabs={ tabs }>
				{ ( tab ) => {
					if ( tab === 'business' ) {
						return <BusinessTab addNotice={ addNotice } />;
					}
					if ( tab === 'about' ) {
						return <AboutTab />;
					}
					return <PostsTab addNotice={ addNotice } />;
				} }
			</TabPanelRoute>
		</Page>
	);
}

mount( App );
