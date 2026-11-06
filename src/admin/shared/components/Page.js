/**
 * Page frame: title, one-sentence description, primary actions, notices, Safe Mode banner.
 * Wraps everything in `.seoc-app` (all plugin CSS is scoped under it).
 */
import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { adminPageUrl, getConfig } from '../config';
import { NoticeList } from './Notices';

/**
 * Safe Mode banner (shown on every page while Safe Mode is on).
 *
 * @return {JSX.Element} Notice.
 */
export function SafeModeBanner() {
	return (
		<Notice
			status="warning"
			isDismissible={ false }
			className="seoc-safe-mode"
		>
			<strong>{ __( 'Safe Mode is on.', 'seo-console' ) }</strong>{ ' ' }
			{ __(
				'SEO Console adds nothing to your public pages until you turn it off. Reports, suggestions and the change log still work.',
				'seo-console'
			) }{ ' ' }
			<a href={ adminPageUrl( 'settings', { tab: 'general' } ) }>
				{ __( 'Safe Mode settings', 'seo-console' ) }
			</a>
		</Notice>
	);
}

/**
 * @param {Object}   props
 * @param {Node}     props.title          Page title (h1).
 * @param {Node}     props.description    One sentence saying what the page is for.
 * @param {Node}     props.meta           Small muted line under the description (freshness, sources).
 * @param {Node}     props.actions        Primary action(s), right of the title.
 * @param {Array}    props.notices        Notices from useNotices().
 * @param {Function} props.onRemoveNotice useNotices().removeNotice.
 * @param {boolean}  props.showSafeMode   Show the Safe Mode banner when it is on (default true).
 * @param {Node}     props.children       Content.
 * @return {JSX.Element} Page.
 */
export default function Page( {
	title,
	description,
	meta,
	actions,
	notices = [],
	onRemoveNotice,
	showSafeMode = true,
	children,
} ) {
	const { safeMode } = getConfig();
	return (
		<div className="seoc-app">
			<header className="seoc-page__header">
				<div className="seoc-page__titles">
					<h1 className="seoc-page__title">{ title }</h1>
					{ description && (
						<p className="seoc-page__description">
							{ description }
						</p>
					) }
					{ meta && <p className="seoc-page__meta">{ meta }</p> }
				</div>
				{ actions && (
					<div className="seoc-page__actions">{ actions }</div>
				) }
			</header>
			{ /* WordPress moves admin notices after the first h1/h2 inside .wrap; this keeps ours in place. */ }
			<hr className="wp-header-end" />
			{ showSafeMode && safeMode && <SafeModeBanner /> }
			<NoticeList notices={ notices } onRemove={ onRemoveNotice } />
			<div className="seoc-page__body">{ children }</div>
		</div>
	);
}
