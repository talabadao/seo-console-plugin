/**
 * Dashboard: first-run checklist, status of AI / SEO plugin / cache, pending suggestions,
 * module cards and the last five changes.
 */
import { Button } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

import {
	Card,
	ChangeLogTable,
	DataHonesty,
	EmptyState,
	MetricTile,
	Page,
	StatusBadge,
	adminPageUrl,
	formatMoney,
	formatNumber,
	getConfig,
	getSettings,
	getSuggestions,
	moduleLabel,
	mount,
	useAsync,
} from '../shared';

const SEO_PLUGIN_NAMES = {
	rankmath: 'Rank Math',
	yoast: 'Yoast SEO',
};

function modelName( id ) {
	switch ( id ) {
		case 'claude-opus-5-5':
			return 'Claude Opus 5.5';
		case 'claude-sonnet-5-5':
			return 'Claude Sonnet 5.5';
		case 'claude-haiku-5-5':
			return 'Claude Haiku 5.5';
		default:
			return id;
	}
}

function ChecklistItem( { done, title, children, href, linkLabel } ) {
	let icon = 'arrow-right-alt';
	let stateText = '';
	if ( done === true ) {
		icon = 'yes-alt';
		stateText = __( 'Done:', 'seo-console' );
	} else if ( done === false ) {
		icon = 'marker';
		stateText = __( 'To do:', 'seo-console' );
	}
	return (
		<li className={ done ? 'is-done' : '' }>
			<span
				className={ `dashicons dashicons-${ icon }` }
				aria-hidden="true"
			/>
			<div>
				<div className="seoc-checklist__title">
					{ stateText && (
						<span className="screen-reader-text">
							{ stateText }{ ' ' }
						</span>
					) }
					{ title }
				</div>
				<div className="seoc-muted">{ children }</div>
				{ ! done && href && (
					<a href={ href }>
						{ linkLabel || __( 'Go there', 'seo-console' ) }
					</a>
				) }
			</div>
		</li>
	);
}

function Checklist( { settings } ) {
	const config = getConfig();
	const keys = settings ? settings.secrets : null;
	const claudeDone = keys
		? keys.claude_api_key.has
		: config.aiConfigured || undefined;
	let googleDone;
	if ( keys ) {
		googleDone = keys.google_api_key.has;
	} else if ( config.googleConfigured !== null ) {
		googleDone = !! config.googleConfigured;
	}
	const checklist = config.checklist || {};
	const moduleStep = ( id ) =>
		config.modules[ id ]
			? adminPageUrl( id )
			: adminPageUrl( 'settings', { tab: 'modules' } );
	const moduleLinkLabel = ( id ) =>
		config.modules[ id ]
			? __( 'Open', 'seo-console' ) +
			  ' ' +
			  moduleLabel( id, config.moduleInfo[ id ] )
			: __( 'Turn the module on first', 'seo-console' );

	const items = [
		{
			key: 'claude',
			done: claudeDone,
			title: __( 'Add your Claude API key', 'seo-console' ),
			text: __(
				'Needed for AI suggestions. Each site uses its own key from console.anthropic.com.',
				'seo-console'
			),
			href: adminPageUrl( 'settings', { tab: 'keys' } ),
			link: __( 'Add the key', 'seo-console' ),
		},
		{
			key: 'google',
			done: googleDone,
			title: __( 'Add your Google API key', 'seo-console' ),
			text: __(
				'Used for PageSpeed Insights and real-visitor (Chrome UX Report) data.',
				'seo-console'
			),
			href: adminPageUrl( 'settings', { tab: 'keys' } ),
			link: __( 'Add the key', 'seo-console' ),
		},
		{
			key: 'speed',
			done:
				typeof checklist.speedTested === 'boolean'
					? checklist.speedTested
					: undefined,
			title: __( 'Run your first speed test', 'seo-console' ),
			text: __(
				'Measures how fast your key pages load on mobile and desktop.',
				'seo-console'
			),
			href: moduleStep( 'speed' ),
			link: moduleLinkLabel( 'speed' ),
		},
		{
			key: 'audit',
			done:
				typeof checklist.auditRun === 'boolean'
					? checklist.auditRun
					: undefined,
			title: __( 'Run your first site audit', 'seo-console' ),
			text: __(
				'Crawls your site for broken links, missing titles, noindex mistakes and more.',
				'seo-console'
			),
			href: moduleStep( 'audit' ),
			link: moduleLinkLabel( 'audit' ),
		},
	];
	if ( items.every( ( i ) => i.done === true ) ) {
		return null;
	}
	const doneCount = items.filter( ( i ) => i.done === true ).length;
	return (
		<Card
			title={ __( 'Get started', 'seo-console' ) }
			description={ sprintf(
				/* translators: 1: steps done, 2: total steps */
				__(
					'%1$d of %2$d steps done. Each step takes a minute or two.',
					'seo-console'
				),
				doneCount,
				items.length
			) }
		>
			<ul className="seoc-checklist">
				{ items.map( ( i ) => (
					<ChecklistItem
						key={ i.key }
						done={ i.done }
						title={ i.title }
						href={ i.href }
						linkLabel={ i.link }
					>
						{ i.text }
					</ChecklistItem>
				) ) }
			</ul>
		</Card>
	);
}

function AiCard( { settings, loading } ) {
	const config = getConfig();
	const configured = settings
		? settings.secrets.claude_api_key.has
		: config.aiConfigured;
	const budget = settings ? settings.general.ai_monthly_budget_usd : null;
	const usage = settings ? settings.aiUsage : null;
	const spent = usage ? usage.monthCostUsd : null;
	const share =
		spent !== null && budget ? Math.min( 1, spent / budget ) : null;
	let meterTone = '';
	if ( share !== null && share >= 1 ) {
		meterTone = 'poor';
	} else if ( share !== null && share >= 0.8 ) {
		meterTone = 'needs-work';
	}

	let status = (
		<StatusBadge status="good" label={ __( 'Ready', 'seo-console' ) } />
	);
	if ( ! configured ) {
		status = (
			<StatusBadge
				status="needs-work"
				label={ __( 'No API key', 'seo-console' ) }
			/>
		);
	} else if ( budget === 0 ) {
		status = (
			<StatusBadge
				status="neutral"
				label={ __( 'Turned off (budget $0)', 'seo-console' ) }
			/>
		);
	} else if ( share !== null && share >= 1 ) {
		status = (
			<StatusBadge
				status="poor"
				label={ __( 'Budget used up', 'seo-console' ) }
			/>
		);
	}

	return (
		<Card
			title={ __( 'AI suggestions', 'seo-console' ) }
			headingLevel={ 3 }
			tone={ ! configured ? 'attention' : undefined }
			actions={
				<a
					href={ adminPageUrl( 'settings', {
						tab: configured ? 'general' : 'keys',
					} ) }
				>
					{ __( 'Settings', 'seo-console' ) }
				</a>
			}
		>
			<p>{ status }</p>
			{ settings && configured && (
				<p className="seoc-muted">
					{ sprintf(
						/* translators: %s: model name */
						__( 'Model: %s', 'seo-console' ),
						modelName( settings.general.ai_model )
					) }
				</p>
			) }
			{ loading && ! settings && (
				<p className="seoc-muted">
					{ __( 'Checking…', 'seo-console' ) }
				</p>
			) }
			{ settings && budget > 0 && (
				<div>
					{ spent !== null ? (
						<>
							<div>
								{ sprintf(
									/* translators: 1: amount spent, 2: monthly budget */
									__(
										'%1$s of %2$s used this month',
										'seo-console'
									),
									formatMoney( spent ),
									formatMoney( budget, 0 )
								) }{ ' ' }
								<DataHonesty kind="estimate" />
							</div>
							<div
								className={ `seoc-meter${
									meterTone
										? ` seoc-meter--${ meterTone }`
										: ''
								}` }
								role="meter"
								aria-valuemin={ 0 }
								aria-valuemax={ budget }
								aria-valuenow={ Math.min( spent, budget ) }
								aria-label={ __(
									'AI budget used this month',
									'seo-console'
								) }
							>
								<span
									className="seoc-meter__fill"
									style={ {
										width: `${ Math.round(
											share * 100
										) }%`,
									} }
								/>
							</div>
						</>
					) : (
						<div>
							{ sprintf(
								/* translators: %s: monthly budget */
								__( 'Monthly budget: %s', 'seo-console' ),
								formatMoney( budget, 0 )
							) }{ ' ' }
							<DataHonesty kind="none" />
						</div>
					) }
				</div>
			) }
		</Card>
	);
}

function SeoPluginCard() {
	const { seoPlugin } = getConfig();
	const name = SEO_PLUGIN_NAMES[ seoPlugin ];
	return (
		<Card title={ __( 'SEO plugin', 'seo-console' ) } headingLevel={ 3 }>
			{ name ? (
				<>
					<p>
						<StatusBadge
							status="good"
							label={ sprintf(
								/* translators: %s: plugin name */
								__( '%s detected', 'seo-console' ),
								name
							) }
						/>
					</p>
					<p className="seoc-muted">
						{ sprintf(
							/* translators: %s: plugin name */
							__(
								'Approved titles and descriptions are saved into %s, so it stays in charge of your SEO output.',
								'seo-console'
							),
							name
						) }
					</p>
				</>
			) : (
				<>
					<p>
						<StatusBadge
							status="needs-work"
							label={ __( 'None detected', 'seo-console' ) }
						/>
					</p>
					<p className="seoc-muted">
						{ __(
							'SEO Console works best next to Rank Math or Yoast SEO. Without one, title and description suggestions cannot be applied.',
							'seo-console'
						) }
					</p>
				</>
			) }
		</Card>
	);
}

function CacheCard() {
	const { cachePlugins } = getConfig();
	return (
		<Card title={ __( 'Cache plugins', 'seo-console' ) } headingLevel={ 3 }>
			{ cachePlugins.length ? (
				<>
					<ul className="seoc-plain-list">
						{ cachePlugins.map( ( p ) => (
							<li key={ p.id || p.name }>
								<strong>{ p.name || p.id }</strong>
								{ p.version ? ` ${ p.version }` : '' }{ ' ' }
								{ p.page_cache ? (
									<StatusBadge
										status="good"
										label={ __(
											'Page cache on',
											'seo-console'
										) }
									/>
								) : (
									<StatusBadge
										status="needs-work"
										label={ __(
											'Page cache off',
											'seo-console'
										) }
									/>
								) }
							</li>
						) ) }
					</ul>
					<p className="seoc-muted">
						{ __(
							'SEO Console clears these caches after every change it applies or undoes.',
							'seo-console'
						) }
					</p>
				</>
			) : (
				<>
					<p>
						<StatusBadge
							status="unknown"
							label={ __( 'None detected', 'seo-console' ) }
						/>
					</p>
					<p className="seoc-muted">
						{ __(
							'A page cache (LiteSpeed Cache, WP Rocket, SiteGround Optimizer…) usually makes the biggest speed difference.',
							'seo-console'
						) }
					</p>
				</>
			) }
		</Card>
	);
}

function PendingCard( { pending } ) {
	let value = null;
	if ( pending.data ) {
		value = pending.data.total;
	}
	return (
		<Card
			title={ __( 'Suggestions waiting', 'seo-console' ) }
			headingLevel={ 3 }
			tone={ value ? 'attention' : undefined }
		>
			{ pending.loading && ! pending.data && (
				<p className="seoc-muted">
					{ __( 'Counting…', 'seo-console' ) }
				</p>
			) }
			{ pending.error && (
				<p>
					<StatusBadge
						status="unknown"
						label={ __( 'Could not count', 'seo-console' ) }
					/>{ ' ' }
					<span className="seoc-muted">
						{ pending.error.message }
					</span>
				</p>
			) }
			{ pending.data && (
				<>
					<MetricTile
						label={ __( 'Waiting for your review', 'seo-console' ) }
						value={ value }
						format={ ( v ) => formatNumber( v ) }
					/>
					<p>
						<a href={ adminPageUrl( 'suggestions' ) }>
							{ value
								? __( 'Review suggestions', 'seo-console' )
								: __( 'Open suggestions', 'seo-console' ) }
						</a>
					</p>
				</>
			) }
		</Card>
	);
}

function ModuleCards() {
	const config = getConfig();
	if (
		Array.isArray( config.dashboardCards ) &&
		config.dashboardCards.length
	) {
		return (
			<div className="seoc-grid">
				{ config.dashboardCards.map( ( c, i ) => (
					<Card
						key={ c.id || c.title || i }
						title={ c.title }
						headingLevel={ 3 }
						tone={ c.status === 'poor' ? 'attention' : undefined }
						actions={
							c.link ? (
								<a href={ c.link }>
									{ c.linkLabel ||
										__( 'Open', 'seo-console' ) }
								</a>
							) : null
						}
					>
						<MetricTile
							label={ c.label || c.title }
							value={ c.value === undefined ? null : c.value }
							unit={ c.unit }
							status={ c.status }
							dataKind={ c.dataKind }
							since={ c.since }
						/>
						{ c.description && (
							<p className="seoc-muted">{ c.description }</p>
						) }
					</Card>
				) ) }
			</div>
		);
	}

	const ids = Object.keys( config.modules );
	if ( ! ids.length ) {
		return (
			<Card title={ __( 'Modules', 'seo-console' ) }>
				<EmptyState title={ __( 'No modules found', 'seo-console' ) }>
					{ __(
						'Modules add the actual tools (speed, audit, AI meta…). Check Settings › Modules.',
						'seo-console'
					) }
				</EmptyState>
			</Card>
		);
	}
	return (
		<div className="seoc-grid">
			{ ids.map( ( id ) => {
				const enabled = !! config.modules[ id ];
				const info = config.moduleInfo[ id ] || {};
				return (
					<Card
						key={ id }
						title={ moduleLabel( id, info ) }
						headingLevel={ 3 }
						actions={
							enabled ? (
								<StatusBadge
									status="good"
									label={ __( 'On', 'seo-console' ) }
								/>
							) : (
								<StatusBadge
									status="neutral"
									label={ __( 'Off', 'seo-console' ) }
								/>
							)
						}
					>
						{ info.description && (
							<p className="seoc-muted">{ info.description }</p>
						) }
						{ enabled ? (
							<a href={ info.page || adminPageUrl( id ) }>
								{ sprintf(
									/* translators: %s: module name */
									__( 'Open %s', 'seo-console' ),
									moduleLabel( id, info )
								) }
							</a>
						) : (
							<a
								href={ adminPageUrl( 'settings', {
									tab: 'modules',
								} ) }
							>
								{ __( 'Turn on in Settings', 'seo-console' ) }
							</a>
						) }
					</Card>
				);
			} ) }
		</div>
	);
}

function App() {
	const settings = useAsync( ( signal ) => getSettings( signal ), [] );
	const pending = useAsync(
		( signal ) =>
			getSuggestions( { status: 'pending', per_page: 1 }, signal ),
		[]
	);
	const count = pending.data ? pending.data.total : 0;

	return (
		<Page
			title={ __( 'SEO Console', 'seo-console' ) }
			description={ __(
				'Technical SEO, page speed and AI suggestions for this site. Nothing changes on your site until a person approves it, and every change can be undone.',
				'seo-console'
			) }
			actions={
				count > 0 ? (
					<Button
						variant="primary"
						href={ adminPageUrl( 'suggestions' ) }
					>
						{ sprintf(
							/* translators: %s: number of suggestions */
							_n(
								'Review %s suggestion',
								'Review %s suggestions',
								count,
								'seo-console'
							),
							formatNumber( count )
						) }
					</Button>
				) : null
			}
		>
			<Checklist settings={ settings.data } />

			<div className="seoc-grid">
				<AiCard
					settings={ settings.data }
					loading={ settings.loading }
				/>
				<SeoPluginCard />
				<CacheCard />
				<PendingCard pending={ pending } />
			</div>
			{ settings.error && (
				<p className="seoc-muted seoc-small">
					{ __( 'Some details could not be loaded:', 'seo-console' ) }{ ' ' }
					{ settings.error.message }
				</p>
			) }

			<ModuleCards />

			<Card
				title={ __( 'Recent changes', 'seo-console' ) }
				description={ __(
					'The last five changes SEO Console made to this site.',
					'seo-console'
				) }
				actions={
					<a href={ adminPageUrl( 'changes' ) }>
						{ __( 'View all changes', 'seo-console' ) }
					</a>
				}
				flush
			>
				<ChangeLogTable compact perPage={ 5 } showFilters={ false } />
			</Card>

			{ getConfig().version && (
				<p className="seoc-source-line">
					{ sprintf(
						/* translators: %s: version */
						__( 'SEO Console %s', 'seo-console' ),
						getConfig().version
					) }
				</p>
			) }
		</Page>
	);
}

mount( App );
