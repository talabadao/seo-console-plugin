/**
 * One post: structured data already on the page vs SEO Console's, the JSON-LD SEO Console adds,
 * items that need input, the pending suggestion, a validator for edited JSON, and Remove.
 */
import { Button, Notice, TextareaControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	Card,
	ConfirmButton,
	ErrorNotice,
	Loading,
	StatusBadge,
	SuggestionList,
	adminPageUrl,
	useAsync,
} from '../shared';
import TypeBadges from './TypeBadges';
import { getPost, removeSchema, validateNodes } from './api';

function Messages( { result } ) {
	if ( ! result ) {
		return null;
	}
	return (
		<>
			{ result.valid ? (
				<p>
					<StatusBadge
						status="good"
						label={ __( 'No problems found', 'seo-console' ) }
					/>
				</p>
			) : (
				<Notice status="error" isDismissible={ false }>
					<p>{ __( 'Problems to fix:', 'seo-console' ) }</p>
					<ul className="seoc-schema-list">
						{ result.errors.map( ( e ) => (
							<li key={ e }>{ e }</li>
						) ) }
					</ul>
				</Notice>
			) }
			{ result.warnings && result.warnings.length > 0 && (
				<details>
					<summary>
						{ sprintf(
							/* translators: %d: number of tips */
							__( '%d optional improvements', 'seo-console' ),
							result.warnings.length
						) }
					</summary>
					<ul className="seoc-schema-list">
						{ result.warnings.map( ( w ) => (
							<li key={ w }>{ w }</li>
						) ) }
					</ul>
				</details>
			) }
		</>
	);
}

function Validate( { id, stored } ) {
	const [ text, setText ] = useState(
		stored ? JSON.stringify( stored, null, 2 ) : ''
	);
	const [ result, setResult ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const run = async () => {
		setError( null );
		setResult( null );
		let nodes;
		try {
			nodes = JSON.parse( text );
		} catch ( e ) {
			setError( {
				message: __(
					'This is not valid JSON. Check for missing commas or quotes.',
					'seo-console'
				),
			} );
			return;
		}
		setBusy( true );
		try {
			setResult( await validateNodes( nodes, id ) );
		} catch ( e ) {
			setError( e );
		} finally {
			setBusy( false );
		}
	};

	return (
		<div className="seoc-schema-editor">
			<TextareaControl
				label={ __( 'Check structured data (JSON)', 'seo-console' ) }
				help={ __(
					'Paste or edit JSON-LD items to check them against this post. Checking does not change anything; to change the page, approve a suggestion.',
					'seo-console'
				) }
				rows={ 10 }
				value={ text }
				onChange={ setText }
				__nextHasNoMarginBottom
			/>
			<p>
				<Button
					variant="secondary"
					onClick={ run }
					isBusy={ busy }
					disabled={ busy || ! text.trim() }
				>
					{ __( 'Validate', 'seo-console' ) }
				</Button>
			</p>
			{ error && <ErrorNotice error={ error } /> }
			<Messages result={ result } />
		</div>
	);
}

export default function PostDetail( { id, onClose, onChanged, addNotice } ) {
	const detail = useAsync( ( signal ) => getPost( id, signal ), [ id ] );
	const [ removing, setRemoving ] = useState( false );

	const remove = async () => {
		setRemoving( true );
		try {
			await removeSchema( id );
			addNotice(
				'success',
				__(
					'Structured data removed. You can undo this in the change log.',
					'seo-console'
				),
				{
					actions: [
						{
							label: __( 'Open the change log', 'seo-console' ),
							url: adminPageUrl( 'changes', {
								module: 'schema',
							} ),
						},
					],
				}
			);
			onChanged();
		} catch ( e ) {
			addNotice( 'error', e.message );
		} finally {
			setRemoving( false );
		}
	};

	const d = detail.data;
	const title = d ? d.post.title : __( 'Post details', 'seo-console' );

	return (
		<Card
			title={ title }
			actions={
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Close', 'seo-console' ) }
				</Button>
			}
		>
			{ detail.loading && ! d && (
				<Loading>{ __( 'Loading…', 'seo-console' ) }</Loading>
			) }
			{ detail.error && (
				<ErrorNotice
					error={ detail.error }
					onRetry={ detail.reload }
					title={ __( 'Could not load this post.', 'seo-console' ) }
				/>
			) }
			{ d && (
				<>
					<p>
						{ d.post.edit_link && (
							<a href={ d.post.edit_link }>
								{ __( 'Edit post', 'seo-console' ) }
							</a>
						) }
						{ d.post.view_link && (
							<>
								{ ' · ' }
								<a href={ d.post.view_link }>
									{ __( 'View post', 'seo-console' ) }
								</a>
							</>
						) }
					</p>

					<h3>{ __( 'Already on the page', 'seo-console' ) }</h3>
					{ d.post.detected.filter( ( f ) => f.source !== 'seoc' )
						.length ? (
						<ul className="seoc-schema-list">
							{ d.post.detected
								.filter( ( f ) => f.source !== 'seoc' )
								.map( ( f ) => (
									<li key={ `${ f.type }-${ f.source }` }>
										<strong>{ f.label }</strong>
										{ ' — ' }
										{ f.detail }
									</li>
								) ) }
						</ul>
					) : (
						<p className="seoc-muted">
							{ __(
								'No structured data found from other sources.',
								'seo-console'
							) }
						</p>
					) }

					<h3>{ __( 'From SEO Console', 'seo-console' ) }</h3>
					<TypeBadges
						items={ d.post.ours.map( ( type ) => ( {
							type,
							variant: 'seoc',
						} ) ) }
						empty={ __( 'Nothing approved yet.', 'seo-console' ) }
					/>
					{ d.validation && <Messages result={ d.validation } /> }
					{ d.preview.hidden.length > 0 && (
						<Notice status="info" isDismissible={ false }>
							<ul className="seoc-schema-list">
								{ d.preview.hidden.map( ( h ) => (
									<li key={ h.type }>
										<strong>{ h.label }</strong>
										{ ': ' }
										{ h.reason }
									</li>
								) ) }
							</ul>
						</Notice>
					) }
					{ d.preview.pieces.length > 0 && (
						<details>
							<summary>
								{ __(
									'Show the JSON-LD SEO Console adds to this page',
									'seo-console'
								) }
							</summary>
							<pre className="seoc-schema-json">
								{ d.preview.json }
							</pre>
						</details>
					) }
					{ d.stored && (
						<p>
							<ConfirmButton
								isDestructive
								isBusy={ removing }
								onConfirm={ remove }
								confirmLabel={ __(
									'Yes, remove it',
									'seo-console'
								) }
								message={ __(
									"Remove SEO Console's structured data from this post? You can undo this in the change log.",
									'seo-console'
								) }
							>
								{ __(
									'Remove structured data',
									'seo-console'
								) }
							</ConfirmButton>
						</p>
					) }

					{ d.incomplete.length > 0 && (
						<>
							<h3>{ __( 'Needs your input', 'seo-console' ) }</h3>
							<ul className="seoc-schema-list">
								{ d.incomplete.map( ( item, i ) => (
									<li key={ `${ item.type }-${ i }` }>
										<strong>{ item.label }</strong>
										{ item.evidence
											? ` — ${ item.evidence }`
											: '' }
										{ item.missing.length > 0 && (
											<div>
												{ sprintf(
													/* translators: %s: list of missing facts */
													__(
														'Missing: %s. Add them to the post, then suggest again.',
														'seo-console'
													),
													item.missing.join( ', ' )
												) }
											</div>
										) }
									</li>
								) ) }
							</ul>
						</>
					) }

					{ d.pending_suggestion && (
						<>
							<h3>
								{ __(
									'Waiting for your review',
									'seo-console'
								) }
							</h3>
							<SuggestionList
								query={ {
									module: 'schema',
									object_type: 'post',
									object_id: id,
									status: 'pending',
								} }
								showFilters={ false }
								onChanged={ onChanged }
							/>
						</>
					) }

					<h3>{ __( 'Check JSON', 'seo-console' ) }</h3>
					<Validate id={ id } stored={ d.stored } />
				</>
			) }
		</Card>
	);
}
