/**
 * "Ask AI" link: opens the AI Chat page with a question about the thing the user is looking at
 * (docs/ARCHITECTURE.md §17.2). Renders nothing when the Chat module is off.
 */
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { adminPageUrl, isModuleEnabled } from '../config';

/**
 * URL of the chat page with a prefilled question.
 *
 * @param {string} question What to ask, in plain words.
 * @param {string} ref      What it is about: "<module>:<type>:<id>", e.g. "audit:rule:title_missing".
 * @return {string} Admin URL.
 */
export function askAiUrl( question, ref = '' ) {
	return adminPageUrl( 'chat', { ask: question, ref } );
}

/**
 * @param {Object} props
 * @param {string} props.question    Question sent to the chat.
 * @param {string} [props.reference] "<module>:<type>:<id>".
 * @param {string} [props.label]     Button text.
 * @param {string} [props.variant]   Button variant.
 * @param {string} [props.size]      Button size.
 */
export default function AskAiButton( {
	question,
	reference = '',
	label,
	variant = 'tertiary',
	size = 'compact',
} ) {
	if ( ! isModuleEnabled( 'chat' ) || ! question ) {
		return null;
	}
	return (
		<Button
			variant={ variant }
			size={ size }
			href={ askAiUrl( question, reference ) }
			icon="format-chat"
		>
			{ label || __( 'Ask AI', 'seo-console' ) }
		</Button>
	);
}
