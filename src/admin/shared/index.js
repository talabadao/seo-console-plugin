/**
 * SEO Console shared admin library. Page entries import from here:
 *
 *   import { Page, Card, apiGet, mount } from '../shared';
 *
 * Importing this module also pulls in the scoped stylesheet (built to build/<page>.css).
 */
import { createRoot } from '@wordpress/element';

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
 * Mount a page component into #seoc-root (no-op when the element is missing).
 *
 * @param {Function} App Root component.
 */
export function mount( App ) {
	const el = document.getElementById( 'seoc-root' );
	if ( el ) {
		createRoot( el ).render( <App /> );
	}
}
