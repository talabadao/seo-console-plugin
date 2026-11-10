/**
 * SEO Console shared admin library. Page entries import from here:
 *
 *   import { Page, Card, apiGet, mount } from '../shared';
 *
 * Importing this module also pulls in the scoped stylesheet (built to build/<page>.css).
 */
import { Component, createRoot, createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import './seoc.scss';

export * from './api';
export * from './config';
export { default as Page, SafeModeBanner } from './components/Page';
export { default as Card } from './components/Card';
export { default as MetricTile, describeDelta } from './components/MetricTile';
export { default as StatusBadge, statusLabel } from './components/StatusBadge';
export { default as EmptyState } from './components/EmptyState';
export { default as DataHonesty, SourceLine } from './components/DataHonesty';
export {
	NoticeList,
	ErrorNotice,
	Loading,
	LiveRegion,
} from './components/Notices';
export { default as ConfirmButton } from './components/ConfirmButton';
export { default as Pagination } from './components/Pagination';
export { default as TabPanelRoute } from './components/TabPanelRoute';
export { default as TrendChart } from './components/TrendChart';
export {
	default as SuggestionList,
	suggestionStatusOptions,
} from './components/SuggestionList';
export {
	default as SuggestionRow,
	ValueBox,
	objectName,
	suggestionStatusBadge,
} from './components/SuggestionRow';
export {
	default as ChangeLogTable,
	changeStatusOptions,
} from './components/ChangeLogTable';
export { default as SerpPreview } from './components/SerpPreview';
export { default as AskAiButton, askAiUrl } from './components/AskAiButton';
export {
	default as CharCounter,
	lengthMessage,
} from './components/CharCounter';
export * from './utils/text';
export * from './utils/chart';
export * from './utils/format';
export * from './utils/hooks';

/**
 * Shows the error message on screen instead of a blank page, so a crash is visible and reportable.
 *
 * @param {string} message The error message to display.
 * @return {Object} A React element.
 */
function crashNotice( message ) {
	const title = __( 'SEO Console could not load this page.', 'seo-console' );
	const hint = __(
		'This is usually a conflict with another plugin or theme script. Please send this message to support, or turn on Safe Mode.',
		'seo-console'
	);
	return createElement(
		'div',
		{
			className: 'seoc-app',
			style: {
				padding: '16px',
				maxWidth: '760px',
			},
		},
		createElement(
			'div',
			{ className: 'notice notice-error', style: { padding: '12px' } },
			createElement( 'p', null, createElement( 'strong', null, title ) ),
			createElement( 'p', null, hint ),
			message
				? createElement(
						'pre',
						{
							style: {
								whiteSpace: 'pre-wrap',
								background: '#f6f7f7',
								padding: '8px',
								overflow: 'auto',
							},
						},
						String( message )
				  )
				: null
		)
	);
}

/**
 * Error boundary: a render-time crash shows the error instead of blanking the page.
 */
class ErrorBoundary extends Component {
	constructor( props ) {
		super( props );
		this.state = { error: null };
	}

	static getDerivedStateFromError( error ) {
		return { error };
	}

	componentDidCatch( error ) {
		// eslint-disable-next-line no-console
		if ( window.console && window.console.error ) {
			window.console.error( 'SEO Console:', error );
		}
	}

	render() {
		if ( this.state.error ) {
			return crashNotice(
				this.state.error && this.state.error.message
					? this.state.error.message
					: this.state.error
			);
		}
		return this.props.children;
	}
}

/**
 * Mount a page component into #seoc-root (no-op when the element is missing). A crash during the first
 * render is shown in the page instead of leaving it blank.
 *
 * @param {Function} App Root component.
 */
export function mount( App ) {
	const el = document.getElementById( 'seoc-root' );
	if ( ! el ) {
		return;
	}
	try {
		createRoot( el ).render(
			createElement( ErrorBoundary, null, createElement( App ) )
		);
	} catch ( error ) {
		// eslint-disable-next-line no-console
		if ( window.console && window.console.error ) {
			window.console.error( 'SEO Console:', error );
		}
		try {
			createRoot( el ).render(
				crashNotice( error && error.message ? error.message : error )
			);
		} catch ( e ) {
			el.textContent =
				( error && error.message ) || 'SEO Console failed to load.';
		}
	}
}
