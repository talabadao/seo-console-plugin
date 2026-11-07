/**
 * Tweaks: safe optimisations SEO Console applies itself, each one logged and undoable.
 */
import { ToggleControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	Card,
	EmptyState,
	ErrorNotice,
	Loading,
	SourceLine,
	StatusBadge,
	adminPageUrl,
	formatRelative,
} from '../shared';
import { setTweak } from './api';

function riskBadge( risk ) {
	return risk === 'medium' ? (
		<StatusBadge
			status="needs-work"
			label={ __( 'Medium risk', 'seo-console' ) }
		/>
	) : (
		<StatusBadge status="good" label={ __( 'Low risk', 'seo-console' ) } />
	);
}

function stateBadge( tweak, safeMode ) {
	if ( ! tweak.enabled ) {
		return (
			<StatusBadge
				status="neutral"
				label={ __( 'Off', 'seo-console' ) }
			/>
		);
	}
	if ( tweak.skipped_reason ) {
		return (
			<StatusBadge
				status="info"
				label={ __( 'On, but skipped', 'seo-console' ) }
			/>
		);
	}
	if ( safeMode && tweak.scope === 'frontend' ) {
		return (
			<StatusBadge
				status="needs-work"
				label={ __( 'Paused by Safe Mode', 'seo-console' ) }
			/>
		);
	}
	return (
		<StatusBadge status="good" label={ __( 'Active', 'seo-console' ) } />
	);
}

export default function TweaksTab( { tweaks, addNotice } ) {
	const [ busy, setBusy ] = useState( null );
	if ( tweaks.loading && ! tweaks.data ) {
		return <Loading />;
	}
	if ( tweaks.error ) {
		return <ErrorNotice error={ tweaks.error } onRetry={ tweaks.reload } />;
	}
	const data = tweaks.data;
	const toggle = ( tweak, enabled ) => {
		setBusy( tweak.id );
		setTweak( tweak.id, enabled ).then(
			( res ) => {
				setBusy( null );
				tweaks.setData( ( d ) => ( {
					...d,
					tweaks: d.tweaks.map( ( t ) =>
						t.id === tweak.id ? res.tweak : t
					),
				} ) );
				addNotice(
					'success',
					enabled
						? sprintf(
								/* translators: %s: tweak name */
								__(
									'"%s" is on. The site was checked and still loads. A new speed test runs in a few minutes.',
									'seo-console'
								),
								tweak.title
						  )
						: sprintf(
								/* translators: %s: tweak name */
								__( '"%s" is off.', 'seo-console' ),
								tweak.title
						  )
				);
			},
			( error ) => {
				setBusy( null );
				addNotice( 'error', error.message );
			}
		);
	};

	return (
		<>
			<Card
				title={ __( 'Safe speed tweaks', 'seo-console' ) }
				description={ __(
					'Each switch is independent. Turning one on or off is checked right away (if the site stops loading it is undone automatically), written to the Change log, and can be undone there.',
					'seo-console'
				) }
			>
				{ data.cache_plugin && (
					<p className="seoc-muted">
						{ sprintf(
							/* translators: %s: cache plugin name */
							__(
								'Tweaks that %s already does are skipped automatically, even when switched on.',
								'seo-console'
							),
							data.cache_plugin.name
						) }
					</p>
				) }
				<ul className="seoc-plain-list seoc-suggestion-list">
					{ data.tweaks.map( ( tweak ) => (
						<li key={ tweak.id } className="seoc-suggestion">
							<div className="seoc-suggestion__head">
								<ToggleControl
									__nextHasNoMarginBottom
									label={ tweak.title }
									checked={ tweak.enabled }
									disabled={ busy !== null }
									onChange={ ( v ) => toggle( tweak, v ) }
								/>{ ' ' }
								{ stateBadge( tweak, data.safe_mode ) }{ ' ' }
								{ riskBadge( tweak.risk ) }
							</div>
							<p>{ tweak.description }</p>
							{ tweak.skipped_note && (
								<p className="seoc-muted">
									{ tweak.skipped_note }
								</p>
							) }
							{ ! tweak.skipped_note && tweak.skips_when && (
								<p className="seoc-muted">
									{ tweak.skips_when }
								</p>
							) }
							{ tweak.last_change && (
								<SourceLine>
									{ sprintf(
										/* translators: 1: on/off, 2: relative time, 3: user name */
										__(
											'Last turned %1$s %2$s by %3$s.',
											'seo-console'
										),
										tweak.last_change.after
											? __( 'on', 'seo-console' )
											: __( 'off', 'seo-console' ),
										formatRelative(
											tweak.last_change.created_at
										),
										tweak.last_change.user_name ||
											__( 'SEO Console', 'seo-console' )
									) }{ ' ' }
									<a
										href={ adminPageUrl( 'changes', {
											module: 'speed',
										} ) }
									>
										{ __(
											'Undo in the Change log',
											'seo-console'
										) }
									</a>
								</SourceLine>
							) }
						</li>
					) ) }
				</ul>
			</Card>
			<Card
				title={ __( 'Data the tweaks use', 'seo-console' ) }
				description={ __(
					'Taken from the latest speed tests and refreshed after every test.',
					'seo-console'
				) }
			>
				{ data.runtime.lcp_images.length === 0 &&
				data.runtime.preconnect.length === 0 ? (
					<EmptyState title={ __( 'No data yet', 'seo-console' ) }>
						{ __( 'Run a speed test first.', 'seo-console' ) }
					</EmptyState>
				) : (
					<>
						<h3>
							{ __( 'Main image per page (LCP)', 'seo-console' ) }
						</h3>
						<ul className="seoc-plain-list">
							{ data.runtime.lcp_images.map( ( img ) => (
								<li key={ img.page_url }>
									<a href={ img.page_url }>
										{ img.page_url }
									</a>
									{ ' → ' }
									<code>{ img.image_url }</code>
								</li>
							) ) }
						</ul>
						<h3>{ __( 'Preconnect to', 'seo-console' ) }</h3>
						<ul className="seoc-plain-list">
							{ data.runtime.preconnect.map( ( o ) => (
								<li key={ o }>
									<code>{ o }</code>
								</li>
							) ) }
						</ul>
					</>
				) }
			</Card>
		</>
	);
}
