/**
 * One API key: status (masked value + where it comes from), replace, delete, optional connection test.
 */
import { Button, TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	Card,
	ConfirmButton,
	LiveRegion,
	StatusBadge,
	normaliseError,
	saveSettings,
} from '../shared';

/**
 * @param {Object}   props
 * @param {string}   props.name        Secret name ("claude_api_key").
 * @param {string}   props.title       Card title.
 * @param {Node}     props.description What the key is for and where to get it.
 * @param {string}   props.constant    wp-config.php constant that overrides it.
 * @param {string}   props.placeholder Input placeholder.
 * @param {Object}   props.secret      { has, masked, source }.
 * @param {Object}   props.previous    Current normalised settings (kept for sections the response omits).
 * @param {Function} props.onSaved     Called with the normalised settings after save/delete.
 * @param {Function} props.onTest      Optional async ( typedKey ) => { ok, message } connection test.
 * @return {JSX.Element} Card.
 */
export default function KeyField( {
	name,
	title,
	description,
	constant,
	placeholder,
	secret,
	previous,
	onSaved,
	onTest,
} ) {
	const [ value, setValue ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ result, setResult ] = useState( null ); // { ok, message }
	const [ testing, setTesting ] = useState( false );
	const fromConstant = secret.source === 'constant';
	const inputId = `seoc-key-${ name }`;

	const save = async ( newValue ) => {
		setBusy( true );
		setResult( null );
		try {
			const settings = await saveSettings(
				{ secrets: { [ name ]: newValue } },
				previous
			);
			setValue( '' );
			setResult( {
				ok: true,
				message: newValue
					? __(
							'Key saved. It is stored encrypted and never shown again in full.',
							'seo-console'
					  )
					: __( 'Key deleted.', 'seo-console' ),
			} );
			onSaved( settings );
		} catch ( e ) {
			setResult( { ok: false, message: normaliseError( e ).message } );
		} finally {
			setBusy( false );
		}
	};

	const test = async () => {
		setTesting( true );
		setResult( null );
		try {
			setResult( await onTest( value.trim() ) );
		} catch ( e ) {
			setResult( { ok: false, message: normaliseError( e ).message } );
		} finally {
			setTesting( false );
		}
	};

	let status;
	if ( fromConstant ) {
		status = (
			<>
				<StatusBadge
					status="good"
					label={ __( 'Set in wp-config.php', 'seo-console' ) }
				/>
				{ secret.masked && (
					<code className="seoc-key__masked">{ secret.masked }</code>
				) }
			</>
		);
	} else if ( secret.has ) {
		status = (
			<>
				<StatusBadge
					status="good"
					label={ __( 'Saved', 'seo-console' ) }
				/>
				{ secret.masked && (
					<code className="seoc-key__masked">{ secret.masked }</code>
				) }
			</>
		);
	} else {
		status = (
			<StatusBadge
				status="needs-work"
				label={ __( 'Not set', 'seo-console' ) }
			/>
		);
	}

	return (
		<Card title={ title } description={ description }>
			<div className="seoc-key">
				<div className="seoc-key__status">{ status }</div>

				{ fromConstant ? (
					<p className="seoc-muted">
						{ sprintf(
							/* translators: %s: PHP constant name */
							__(
								'This key comes from the %s constant in wp-config.php, so it cannot be changed here. Edit wp-config.php to change or remove it.',
								'seo-console'
							),
							constant
						) }
					</p>
				) : (
					<form
						className="seoc-key__row"
						onSubmit={ ( e ) => {
							e.preventDefault();
							if ( value.trim() ) {
								save( value.trim() );
							}
						} }
					>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							id={ inputId }
							type="password"
							autoComplete="off"
							spellCheck={ false }
							label={
								secret.has
									? __(
											'Replace with a new key',
											'seo-console'
									  )
									: __( 'API key', 'seo-console' )
							}
							placeholder={ placeholder }
							value={ value }
							onChange={ setValue }
						/>
						<Button
							variant="primary"
							type="submit"
							isBusy={ busy }
							disabled={ busy || ! value.trim() }
						>
							{ __( 'Save key', 'seo-console' ) }
						</Button>
					</form>
				) }

				<div className="seoc-form__actions">
					{ onTest && (
						<Button
							variant="secondary"
							onClick={ test }
							isBusy={ testing }
							disabled={
								testing || ( ! secret.has && ! value.trim() )
							}
						>
							{ value.trim()
								? __( 'Test this key', 'seo-console' )
								: __( 'Test connection', 'seo-console' ) }
						</Button>
					) }
					{ ! fromConstant && secret.has && (
						<ConfirmButton
							isDestructive
							isBusy={ busy }
							message={ __(
								'Delete this key? Features that need it will stop.',
								'seo-console'
							) }
							confirmLabel={ __( 'Yes, delete', 'seo-console' ) }
							onConfirm={ () => save( '' ) }
						>
							{ __( 'Delete key', 'seo-console' ) }
						</ConfirmButton>
					) }
				</div>

				<LiveRegion visible>
					{ result && (
						<span>
							<StatusBadge
								status={ result.ok ? 'good' : 'poor' }
								label={
									result.ok
										? __( 'OK', 'seo-console' )
										: __( 'Problem', 'seo-console' )
								}
							/>{ ' ' }
							{ result.message }
						</span>
					) }
				</LiveRegion>
			</div>
		</Card>
	);
}
