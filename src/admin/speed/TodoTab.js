/**
 * To do: recommendations grouped by who can fix them.
 */
import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { Card, EmptyState, ErrorNotice, Loading, StatusBadge } from '../shared';
import { setTweak } from './api';
import { effortLabel, impactLabel, impactStatus, strategyLabel } from './utils';

const GROUPS = [
	{
		id: 'seo-console',
		title: () => __( 'SEO Console can fix this', 'seo-console' ),
		description: () =>
			__(
				'One click. Each change is checked right away (the site must still load), logged, and can be undone from the Change log.',
				'seo-console'
			),
	},
	{
		id: 'cache-plugin',
		title: () =>
			__( 'Change a setting in your cache plugin', 'seo-console' ),
		description: () =>
			__(
				'SEO Console never changes another plugin’s settings. Follow the steps and open the settings page with the link.',
				'seo-console'
			),
	},
	{
		id: 'developer',
		title: () => __( 'Developer tasks (Claude Code)', 'seo-console' ),
		description: () =>
			__(
				'Changes to the theme or code. Copy the task and give it to your developer or to Claude Code.',
				'seo-console'
			),
	},
	{
		id: 'hosting',
		title: () => __( 'Ask your hosting provider', 'seo-console' ),
		description: () =>
			__(
				'Server settings only your host can change. Copy the request and send it to their support.',
				'seo-console'
			),
	},
	{
		id: 'none',
		title: () => __( 'Already handled', 'seo-console' ),
		description: () =>
			__(
				'The usual fixes are already switched on. If PageSpeed still reports these, look at the affected pages.',
				'seo-console'
			),
	},
];

function taskMarkdown( item ) {
	const fixer = item.fixer || {};
	const lines = [
		`### ${ item.title }`,
		'',
		item.what,
		'',
		`**Why it matters:** ${ item.why }`,
		'',
		`**Task:** ${ fixer.task || item.how }`,
	];
	if ( item.urls && item.urls.length ) {
		lines.push( '', '**Pages:**' );
		item.urls.forEach( ( u ) =>
			lines.push( `- ${ u.url } (${ u.strategy })` )
		);
	}
	return lines.join( '\n' );
}

function CopyButton( { text } ) {
	const [ copied, setCopied ] = useState( false );
	const copy = () => {
		const done = () => {
			setCopied( true );
			window.setTimeout( () => setCopied( false ), 2500 );
		};
		if ( window.navigator.clipboard ) {
			window.navigator.clipboard.writeText( text ).then( done, () => {} );
		}
	};
	return (
		<Button variant="secondary" size="compact" onClick={ copy }>
			{ copied
				? __( 'Copied', 'seo-console' )
				: __( 'Copy task', 'seo-console' ) }
		</Button>
	);
}

function FixerBlock( { item, fixer, onTweak, busy } ) {
	if ( ! fixer ) {
		return null;
	}
	switch ( fixer.type ) {
		case 'seo-console':
			return (
				<p>
					<Button
						variant="primary"
						isBusy={ busy }
						disabled={ busy }
						onClick={ () => onTweak( fixer.tweak ) }
					>
						{ __( 'Turn on', 'seo-console' ) }
					</Button>{ ' ' }
					<span className="seoc-muted">{ fixer.label }</span>
				</p>
			);
		case 'cache-plugin':
			return (
				<>
					<p>
						<strong>{ fixer.label }</strong>
					</p>
					<p>{ fixer.how_to }</p>
					{ fixer.settings_url && (
						<p>
							<a href={ fixer.settings_url }>
								{ __(
									'Open the plugin settings',
									'seo-console'
								) }
							</a>
						</p>
					) }
				</>
			);
		case 'developer':
			return (
				<>
					<pre className="seoc-json">
						{ taskMarkdown( { ...item, fixer } ) }
					</pre>
					<CopyButton text={ taskMarkdown( { ...item, fixer } ) } />
				</>
			);
		case 'hosting':
			return (
				<>
					<p>
						<strong>{ __( 'What to ask:', 'seo-console' ) }</strong>{ ' ' }
						{ fixer.task }
					</p>
					<CopyButton text={ fixer.task } />
				</>
			);
		default:
			return null;
	}
}

function TodoItem( { item, onTweak, busyTweak } ) {
	const fixer = item.fixer;
	return (
		<li className="seoc-suggestion">
			<div className="seoc-suggestion__head">
				<strong className="seoc-suggestion__title">
					{ item.title }
				</strong>{ ' ' }
				<StatusBadge
					status={ impactStatus( item.impact ) }
					label={ impactLabel( item.impact ) }
				/>{ ' ' }
				<span className="seoc-tag">{ effortLabel( item.effort ) }</span>
			</div>
			<p>{ item.what }</p>
			<p className="seoc-muted">{ item.why }</p>
			<FixerBlock
				item={ item }
				fixer={ fixer }
				onTweak={ onTweak }
				busy={ fixer && busyTweak === fixer.tweak }
			/>
			{ item.already_done && item.already_done.length > 0 && (
				<ul className="seoc-plain-list seoc-muted">
					{ item.already_done.map( ( note ) => (
						<li key={ note }>✓ { note }</li>
					) ) }
				</ul>
			) }
			<details>
				<summary>
					{ sprintf(
						/* translators: %d: number of page tests */
						_n(
							'Found on %d page test',
							'Found on %d page tests',
							item.urls.length,
							'seo-console'
						),
						item.urls.length
					) }
					{ item.alternatives && item.alternatives.length > 0
						? ` · ${ __( 'other ways to fix it', 'seo-console' ) }`
						: '' }
				</summary>
				<ul className="seoc-plain-list">
					{ item.urls.map( ( u ) => (
						<li key={ `${ u.url }-${ u.strategy }` }>
							<a href={ u.url } target="_blank" rel="noreferrer">
								{ u.label || u.url }
							</a>{ ' ' }
							<span className="seoc-muted">
								{ strategyLabel( u.strategy ) }
								{ u.display_value
									? ` · ${ u.display_value }`
									: '' }
							</span>
						</li>
					) ) }
				</ul>
				<p>
					<strong>{ __( 'How to fix:', 'seo-console' ) }</strong>{ ' ' }
					{ item.how }
				</p>
				{ ( item.alternatives || [] ).map( ( alt, i ) => (
					<div key={ i } className="seoc-card__description">
						<FixerBlock
							item={ item }
							fixer={ alt }
							onTweak={ onTweak }
							busy={ busyTweak === alt.tweak }
						/>
					</div>
				) ) }
			</details>
		</li>
	);
}

export default function TodoTab( { recs, onChanged, addNotice } ) {
	const [ busyTweak, setBusyTweak ] = useState( null );
	if ( recs.loading && ! recs.data ) {
		return <Loading />;
	}
	if ( recs.error ) {
		return <ErrorNotice error={ recs.error } onRetry={ recs.reload } />;
	}
	const data = recs.data || { items: [] };
	if ( ! data.items.length ) {
		return (
			<Card>
				<EmptyState
					icon="yes-alt"
					title={
						data.has_results
							? __( 'Nothing to do right now', 'seo-console' )
							: __( 'Run a speed test first', 'seo-console' )
					}
				>
					{ data.has_results
						? __(
								'The latest tests found no problems SEO Console knows how to explain.',
								'seo-console'
						  )
						: __(
								'The to-do list is built from the PageSpeed Insights results of your pages.',
								'seo-console'
						  ) }
				</EmptyState>
			</Card>
		);
	}

	const turnOn = ( tweak ) => {
		setBusyTweak( tweak );
		setTweak( tweak, true ).then(
			() => {
				setBusyTweak( null );
				addNotice(
					'success',
					__(
						'Turned on. The site was checked and still loads; a new speed test will run in a few minutes. You can undo it in the Change log.',
						'seo-console'
					)
				);
				onChanged();
			},
			( error ) => {
				setBusyTweak( null );
				addNotice( 'error', error.message );
			}
		);
	};

	return GROUPS.map( ( group ) => {
		const items = data.items.filter( ( i ) => i.group === group.id );
		if ( ! items.length ) {
			return null;
		}
		return (
			<Card
				key={ group.id }
				title={ group.title() }
				description={ group.description() }
			>
				<ul className="seoc-suggestion-list seoc-plain-list">
					{ items.map( ( item ) => (
						<TodoItem
							key={ item.id }
							item={ item }
							onTweak={ turnOn }
							busyTweak={ busyTweak }
						/>
					) ) }
				</ul>
			</Card>
		);
	} );
}
