/**
 * A button that asks for confirmation inline before running its action.
 */
import { Button } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * @param {Object}   props
 * @param {Node}     props.children      Button label.
 * @param {Node}     props.message       Question shown while confirming ("Undo this change?").
 * @param {string}   props.confirmLabel  Confirm button label (default "Yes, continue").
 * @param {string}   props.cancelLabel   Cancel label (default "Cancel").
 * @param {Function} props.onConfirm     Called on confirm (may return a promise).
 * @param {string}   props.variant       Button variant for the first button (default "secondary").
 * @param {boolean}  props.isDestructive Red styling for the confirm button.
 * @param {boolean}  props.isBusy        Show busy state.
 * @param {boolean}  props.disabled      Disable.
 * @param {string}   props.size          Button size ("small" | "compact" | "default").
 * @return {JSX.Element} Button or confirm group.
 */
export default function ConfirmButton( {
	children,
	message,
	confirmLabel,
	cancelLabel,
	onConfirm,
	variant = 'secondary',
	isDestructive = false,
	isBusy = false,
	disabled = false,
	size,
} ) {
	const [ asking, setAsking ] = useState( false );
	const confirmRef = useRef( null );
	const triggerRef = useRef( null );
	const wasAsking = useRef( false );

	useEffect( () => {
		if ( asking && confirmRef.current ) {
			confirmRef.current.focus();
		} else if ( ! asking && wasAsking.current && triggerRef.current ) {
			triggerRef.current.focus();
		}
		wasAsking.current = asking;
	}, [ asking ] );

	const small = size === 'small' ? { size: 'small' } : {};
	if ( size === 'compact' ) {
		small.size = 'compact';
	}

	if ( ! asking ) {
		return (
			<Button
				ref={ triggerRef }
				variant={ variant }
				isDestructive={ isDestructive && variant !== 'primary' }
				isBusy={ isBusy }
				disabled={ disabled || isBusy }
				onClick={ () => setAsking( true ) }
				{ ...small }
			>
				{ children }
			</Button>
		);
	}

	return (
		<span
			className="seoc-confirm"
			role="group"
			aria-label={ typeof message === 'string' ? message : undefined }
		>
			{ message && (
				<span className="seoc-confirm__message">{ message }</span>
			) }
			<Button
				ref={ confirmRef }
				variant="primary"
				isDestructive={ isDestructive }
				onClick={ async () => {
					setAsking( false );
					await onConfirm();
				} }
				onKeyDown={ ( e ) => {
					if ( e.key === 'Escape' ) {
						setAsking( false );
					}
				} }
				{ ...small }
			>
				{ confirmLabel || __( 'Yes, continue', 'seo-console' ) }
			</Button>
			<Button
				variant="tertiary"
				onClick={ () => setAsking( false ) }
				{ ...small }
			>
				{ cancelLabel || __( 'Cancel', 'seo-console' ) }
			</Button>
		</span>
	);
}
