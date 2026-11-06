/**
 * Settings: General, API keys, Modules. Saved through POST /settings.
 */
import {
	Button,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	Card,
	EmptyState,
	ErrorNotice,
	Loading,
	Page,
	StatusBadge,
	TabPanelRoute,
	formatMoney,
	getConfig,
	getSettings,
	mount,
	normaliseError,
	saveSettings,
	testAi,
	useAsync,
	useNotices,
} from '../shared';
import KeyField from './KeyField';

const SEO_PLUGIN_NAMES = { rankmath: 'Rank Math', yoast: 'Yoast SEO' };

function modelOptions() {
	return [
		{
			value: 'claude-opus-5-5',
			label: __( 'Claude Opus 5.5 (default)', 'seo-console' ),
			hint: __(
				'Best quality. About $4 per million input tokens and $20 per million output tokens.',
				'seo-console'
			),
		},
		{
			value: 'claude-sonnet-5-5',
			label: __( 'Claude Sonnet 5.5', 'seo-console' ),
			hint: __(
				'Very good quality at half the price: about $2 / $10 per million tokens (input / output).',
				'seo-console'
			),
		},
		{
			value: 'claude-haiku-5-5',
			label: __( 'Claude Haiku 5.5', 'seo-console' ),
			hint: __(
				'Fastest and cheapest: about $0.10 / $0.50 per million tokens. Fine for alt text; weaker for titles and descriptions.',
				'seo-console'
			),
		},
	];
}

function aiErrorMessage( error ) {
	switch ( error.code ) {
		case 'seoc_ai_auth':
			return __(
				'Anthropic rejected the key. Check that you copied the whole key and that it is still active.',
				'seo-console'
			);
		case 'seoc_ai_budget':
			return __(
				'This month’s AI budget is used up, so no request was sent. Raise the budget on the General tab to test.',
				'seo-console'
			);
		case 'seoc_ai_unavailable':
			return __(
				'Claude is busy or unavailable right now. Try again in a few minutes.',
				'seo-console'
			);
		case 'seoc_ai_network':
			return __(
				'Your server could not reach api.anthropic.com. Your host may block outgoing connections.',
				'seo-console'
			);
		case 'seoc_ai_request':
			return (
				__(
					'Anthropic did not accept the request. The selected model may not be available for this key.',
					'seo-console'
				) +
				' ' +
				error.message
			);
		default:
			return error.message;
	}
}

function draftFrom( settings ) {
	const modules = {};
	Object.values( settings.modules ).forEach( ( m ) => {
		modules[ m.id ] = m.enabled;
	} );
	return { general: { ...settings.general }, modules };
}

function GeneralTab( { draft, setGeneral, settings } ) {
	const g = draft.general;
	const opts = modelOptions();
	const current = opts.find( ( o ) => o.value === g.ai_model );
	const detected = SEO_PLUGIN_NAMES[ getConfig().seoPlugin ];
	const usage = settings.aiUsage;
	let budgetHelp = __(
		'When this month’s estimated cost reaches the budget, AI features pause until next month. 0 turns AI off.',
		'seo-console'
	);
	if ( usage && usage.monthCostUsd !== null ) {
		budgetHelp =
			sprintf(
				/* translators: %s: amount */
				__( 'Estimated spend this month: %s.', 'seo-console' ),
				formatMoney( usage.monthCostUsd )
			) +
			' ' +
			budgetHelp;
	}

	return (
		<div className="seoc-form">
			<Card title={ __( 'AI', 'seo-console' ) } headingLevel={ 3 }>
				<div className="seoc-form">
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'AI model', 'seo-console' ) }
						value={ g.ai_model }
						options={ opts.map( ( o ) => ( {
							value: o.value,
							label: o.label,
						} ) ) }
						onChange={ ( v ) => setGeneral( { ai_model: v } ) }
						help={ current ? current.hint : '' }
					/>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						type="number"
						min={ 0 }
						step={ 1 }
						label={ __( 'Monthly AI budget (USD)', 'seo-console' ) }
						value={ String( g.ai_monthly_budget_usd ) }
						onChange={ ( v ) => {
							const n = parseInt( v, 10 );
							setGeneral( {
								ai_monthly_budget_usd:
									Number.isFinite( n ) && n >= 0 ? n : 0,
							} );
						} }
						help={ budgetHelp }
					/>
				</div>
			</Card>

			<Card
				title={ __( 'SEO plugin', 'seo-console' ) }
				headingLevel={ 3 }
			>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __(
						'Write titles and descriptions to',
						'seo-console'
					) }
					value={ g.seo_plugin }
					options={ [
						{
							value: 'auto',
							label: __(
								'Detect automatically (recommended)',
								'seo-console'
							),
						},
						{ value: 'rankmath', label: 'Rank Math' },
						{ value: 'yoast', label: 'Yoast SEO' },
						{ value: 'none', label: __( 'None', 'seo-console' ) },
					] }
					onChange={ ( v ) => setGeneral( { seo_plugin: v } ) }
					help={
						detected
							? sprintf(
									/* translators: %s: plugin name */
									__(
										'Detected now: %s. SEO Console never outputs its own titles or meta tags; it saves them into this plugin.',
										'seo-console'
									),
									detected
							  )
							: __(
									'No SEO plugin detected. With "None", title and description suggestions cannot be applied.',
									'seo-console'
							  )
					}
				/>
			</Card>

			<Card
				title={ __( 'Safe Mode', 'seo-console' ) }
				headingLevel={ 3 }
				tone={ g.safe_mode ? 'attention' : undefined }
			>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Safe Mode', 'seo-console' ) }
					checked={ g.safe_mode }
					onChange={ ( v ) => setGeneral( { safe_mode: v } ) }
					help={ __(
						'When on, SEO Console adds nothing to your public pages: no speed tweaks, no schema, no tags. Use it if something looks wrong on the site. Reports, suggestions and the change log keep working.',
						'seo-console'
					) }
				/>
			</Card>

			<Card
				title={ __( 'When the plugin is deleted', 'seo-console' ) }
				headingLevel={ 3 }
			>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Keep SEO Console data', 'seo-console' ) }
					checked={ g.keep_data_on_uninstall }
					onChange={ ( v ) =>
						setGeneral( { keep_data_on_uninstall: v } )
					}
					help={
						g.keep_data_on_uninstall
							? __(
									'Reports, settings and the change log stay in the database if you delete the plugin, so you can reinstall without losing history.',
									'seo-console'
							  )
							: __(
									'Deleting the plugin removes all its data: reports, settings, keys and the change log. Changes already applied to your site stay.',
									'seo-console'
							  )
					}
				/>
			</Card>
		</div>
	);
}

function ModulesTab( { draft, setModule, settings } ) {
	const modules = Object.values( settings.modules );
	if ( ! modules.length ) {
		return (
			<EmptyState title={ __( 'No modules found', 'seo-console' ) }>
				{ __(
					'The server did not report any modules.',
					'seo-console'
				) }
			</EmptyState>
		);
	}
	return (
		<Card
			title={ __( 'Modules', 'seo-console' ) }
			description={ __(
				'Turn tools on or off. Turning a module off stops its background jobs; its data is kept.',
				'seo-console'
			) }
		>
			<ul className="seoc-module-list">
				{ modules.map( ( m ) => (
					<li key={ m.id }>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ m.title }
							help={ m.description }
							checked={ !! draft.modules[ m.id ] }
							onChange={ ( v ) => setModule( m.id, v ) }
						/>
					</li>
				) ) }
			</ul>
		</Card>
	);
}

function KeysTab( { settings, draft, onSaved } ) {
	return (
		<div className="seoc-form">
			<KeyField
				name="claude_api_key"
				title={ __( 'Claude API key', 'seo-console' ) }
				description={ __(
					'Used for AI suggestions. Create one at console.anthropic.com › API keys. It is stored encrypted on this site and never sent to the browser.',
					'seo-console'
				) }
				constant="SEOC_CLAUDE_API_KEY"
				placeholder="sk-ant-…"
				secret={ settings.secrets.claude_api_key }
				previous={ settings }
				onSaved={ onSaved }
				onTest={ async ( typed ) => {
					try {
						const body = { model: draft.general.ai_model };
						if ( typed ) {
							body.api_key = typed;
						}
						const r = await testAi( body );
						return {
							ok: r.ok,
							message:
								r.message ||
								sprintf(
									/* translators: %s: model id */
									__(
										'Connected. Claude answered using %s.',
										'seo-console'
									),
									r.model || draft.general.ai_model
								),
						};
					} catch ( e ) {
						return {
							ok: false,
							message: aiErrorMessage( normaliseError( e ) ),
						};
					}
				} }
			/>
			<KeyField
				name="google_api_key"
				title={ __( 'Google API key', 'seo-console' ) }
				description={ __(
					'Used for PageSpeed Insights and Chrome UX Report data. Create it in Google Cloud Console and enable both APIs for it. It is checked the first time a speed test runs.',
					'seo-console'
				) }
				constant="SEOC_GOOGLE_API_KEY"
				placeholder="AIza…"
				secret={ settings.secrets.google_api_key }
				previous={ settings }
				onSaved={ onSaved }
			/>
		</div>
	);
}

function App() {
	const { notices, addNotice, removeNotice } = useNotices();
	const loaded = useAsync( ( signal ) => getSettings( signal ), [] );
	const [ settings, setSettings ] = useState( null );
	const [ draft, setDraft ] = useState( null );
	const [ saving, setSaving ] = useState( false );

	useEffect( () => {
		if ( loaded.data ) {
			setSettings( loaded.data );
			setDraft( draftFrom( loaded.data ) );
		}
	}, [ loaded.data ] );

	const dirty =
		!! settings &&
		!! draft &&
		JSON.stringify( draftFrom( settings ) ) !== JSON.stringify( draft );

	useEffect( () => {
		const warn = ( e ) => {
			if ( dirty ) {
				e.preventDefault();
				e.returnValue = '';
			}
		};
		window.addEventListener( 'beforeunload', warn );
		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ dirty ] );

	const applySaved = ( next ) => {
		setSettings( next );
		const config = getConfig();
		config.safeMode = next.general.safe_mode;
		Object.values( next.modules ).forEach( ( m ) => {
			config.modules[ m.id ] = m.enabled;
		} );
		config.aiConfigured = next.secrets.claude_api_key.has;
	};

	const save = async () => {
		setSaving( true );
		removeNotice( 'save' );
		const modulesChanged =
			JSON.stringify( draftFrom( settings ).modules ) !==
			JSON.stringify( draft.modules );
		try {
			const modules = {};
			Object.keys( draft.modules ).forEach( ( id ) => {
				modules[ id ] = { enabled: draft.modules[ id ] };
			} );
			const next = await saveSettings(
				{ general: draft.general, modules },
				settings
			);
			applySaved( next );
			setDraft( draftFrom( next ) );
			addNotice(
				'success',
				modulesChanged
					? __(
							'Settings saved. Reload the page to update the SEO Console menu.',
							'seo-console'
					  )
					: __( 'Settings saved.', 'seo-console' ),
				{ id: 'save' }
			);
		} catch ( e ) {
			addNotice( 'error', normaliseError( e ).message, { id: 'save' } );
		} finally {
			setSaving( false );
		}
	};

	const saveBar = (
		<div className="seoc-form__actions">
			<Button
				variant="primary"
				onClick={ save }
				isBusy={ saving }
				disabled={ saving || ! dirty }
			>
				{ __( 'Save changes', 'seo-console' ) }
			</Button>
			{ dirty && (
				<StatusBadge
					status="needs-work"
					label={ __( 'Unsaved changes', 'seo-console' ) }
				/>
			) }
		</div>
	);

	return (
		<Page
			title={ __( 'Settings', 'seo-console' ) }
			description={ __(
				'Choose the AI model and budget, add your API keys, and turn modules on or off.',
				'seo-console'
			) }
			notices={ notices }
			onRemoveNotice={ removeNotice }
		>
			{ loaded.loading && ! settings && (
				<Loading>{ __( 'Loading settings…', 'seo-console' ) }</Loading>
			) }
			{ loaded.error && (
				<ErrorNotice
					title={ __( 'Could not load settings.', 'seo-console' ) }
					error={ loaded.error }
					onRetry={ loaded.reload }
				/>
			) }
			{ settings && draft && (
				<TabPanelRoute
					tabs={ [
						{
							name: 'general',
							title: __( 'General', 'seo-console' ),
						},
						{
							name: 'keys',
							title: __( 'API keys', 'seo-console' ),
						},
						{
							name: 'modules',
							title: __( 'Modules', 'seo-console' ),
						},
					] }
				>
					{ ( tab ) => {
						if ( tab === 'keys' ) {
							return (
								<KeysTab
									settings={ settings }
									draft={ draft }
									onSaved={ applySaved }
								/>
							);
						}
						if ( tab === 'modules' ) {
							return (
								<>
									<ModulesTab
										draft={ draft }
										settings={ settings }
										setModule={ ( id, v ) =>
											setDraft( ( d ) => ( {
												...d,
												modules: {
													...d.modules,
													[ id ]: v,
												},
											} ) )
										}
									/>
									{ saveBar }
								</>
							);
						}
						return (
							<>
								<GeneralTab
									draft={ draft }
									settings={ settings }
									setGeneral={ ( patch ) =>
										setDraft( ( d ) => ( {
											...d,
											general: { ...d.general, ...patch },
										} ) )
									}
								/>
								{ saveBar }
							</>
						);
					} }
				</TabPanelRoute>
			) }
		</Page>
	);
}

mount( App );
