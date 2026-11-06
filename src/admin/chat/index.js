/**
 * AI Chat page: conversations with Claude about this site, and "Claude Code tasks" for code-level work.
 */
import { Button, Spinner, TextareaControl } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import {
	Card,
	ConfirmButton,
	EmptyState,
	ErrorNotice,
	Loading,
	LiveRegion,
	Page,
	adminPageUrl,
	apiGet,
	apiPost,
	buildPath,
	formatDate,
	formatMoney,
	formatRelative,
	getConfig,
	getUrlParams,
	mount,
	normaliseError,
	setUrlParams,
	useAsync,
} from '../shared';
import Markdown from './markdown';
import './style.scss';

const MAX_CHARS = 8000;

function removeConversation( id ) {
	return apiFetch( {
		path: buildPath( `chat/conversations/${ encodeURIComponent( id ) }` ),
		method: 'DELETE',
	} ).catch( ( e ) => {
		throw normaliseError( e );
	} );
}

function CostNote( { usage } ) {
	if ( ! usage ) {
		return null;
	}
	return (
		<p className="seoc-muted seoc-small">
			{ sprintf(
				/* translators: 1: AI cost this month, 2: monthly budget */
				__(
					'AI cost this month: %1$s of your %2$s budget. Each message sends your site summary and the recent conversation to Claude, so long conversations cost more.',
					'seo-console'
				),
				formatMoney( usage.month_cost_usd ),
				formatMoney( usage.budget_usd, 0 )
			) }
		</p>
	);
}

function Message( { message } ) {
	const mine = message.role === 'user';
	return (
		<li
			className={ `seoc-chat__message seoc-chat__message--${
				mine ? 'user' : 'assistant'
			}` }
		>
			<div className="seoc-chat__who">
				{ mine
					? __( 'You', 'seo-console' )
					: __( 'Claude', 'seo-console' ) }
				{ message.created_at && (
					<span
						className="seoc-muted"
						title={ formatDate( message.created_at ) }
					>
						{ ' · ' }
						{ formatRelative( message.created_at ) }
					</span>
				) }
			</div>
			<div className="seoc-chat__bubble">
				{ mine ? (
					<p className="seoc-chat__plain">{ message.content }</p>
				) : (
					<Markdown text={ message.content } />
				) }
			</div>
		</li>
	);
}

function TaskPanel( { conversationId, defaultGoal } ) {
	const [ goal, setGoal ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ result, setResult ] = useState( null );
	const [ copied, setCopied ] = useState( false );

	const write = async () => {
		setBusy( true );
		setError( null );
		setCopied( false );
		try {
			setResult(
				await apiPost( 'chat/task', {
					goal: goal.trim() || defaultGoal,
					conversation_id: conversationId || '',
				} )
			);
		} catch ( e ) {
			setError( normaliseError( e ) );
		}
		setBusy( false );
	};

	const copy = async () => {
		try {
			await window.navigator.clipboard.writeText( result.markdown );
			setCopied( true );
		} catch ( e ) {
			setCopied( false );
			setError( {
				code: 'seoc_copy',
				message: __(
					'Copying is blocked by the browser. Select the text and copy it yourself.',
					'seo-console'
				),
			} );
		}
	};

	return (
		<Card
			title={ __( 'Turn this into a Claude Code task', 'seo-console' ) }
			description={ __(
				'For fixes that need code (theme, snippets, server settings). Claude writes a precise task you paste into Claude Code, which changes your site’s Git repository through a pull request you review.',
				'seo-console'
			) }
		>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'What should be done?', 'seo-console' ) }
				help={
					conversationId
						? __(
								'Leave empty to use your last question. The recent messages of this conversation are included as context.',
								'seo-console'
						  )
						: __(
								'Describe the goal in your own words.',
								'seo-console'
						  )
				}
				value={ goal }
				onChange={ setGoal }
				rows={ 3 }
			/>
			<p>
				<Button
					variant="secondary"
					onClick={ write }
					isBusy={ busy }
					disabled={ busy || ! ( goal.trim() || defaultGoal ) }
				>
					{ busy
						? __( 'Writing the task…', 'seo-console' )
						: __( 'Write task', 'seo-console' ) }
				</Button>
			</p>
			<ErrorNotice error={ error } />
			{ result && (
				<div className="seoc-chat__task">
					<div className="seoc-toolbar">
						<strong>{ result.task.title }</strong>
						<Button variant="secondary" onClick={ copy }>
							{ copied
								? __( 'Copied', 'seo-console' )
								: __( 'Copy Markdown', 'seo-console' ) }
						</Button>
					</div>
					<pre className="seoc-chat__task-md" tabIndex={ 0 }>
						{ result.markdown }
					</pre>
				</div>
			) }
		</Card>
	);
}

function App() {
	const config = getConfig();
	const [ activeId, setActiveId ] = useState(
		getUrlParams().get( 'conversation' ) || ''
	);
	const [ messages, setMessages ] = useState( [] );
	const [ text, setText ] = useState( '' );
	const [ sending, setSending ] = useState( false );
	const [ sendError, setSendError ] = useState( null );
	const [ usage, setUsage ] = useState( null );
	const [ announce, setAnnounce ] = useState( '' );
	const threadEnd = useRef( null );

	const list = useAsync( ( signal ) =>
		apiGet( 'chat/conversations', {}, signal )
	);
	useEffect( () => {
		if ( list.data && list.data.ai_usage ) {
			setUsage( list.data.ai_usage );
		}
	}, [ list.data ] );

	const thread = useAsync(
		( signal ) =>
			activeId
				? apiGet(
						`chat/conversations/${ encodeURIComponent(
							activeId
						) }`,
						{},
						signal
				  )
				: Promise.resolve( { messages: [] } ),
		[ activeId ]
	);
	useEffect( () => {
		if ( thread.data ) {
			setMessages( thread.data.messages || [] );
		}
	}, [ thread.data ] );
	useEffect( () => {
		if ( threadEnd.current && threadEnd.current.scrollIntoView ) {
			threadEnd.current.scrollIntoView( { block: 'nearest' } );
		}
	}, [ messages.length, sending ] );

	const open = ( id ) => {
		setActiveId( id );
		setSendError( null );
		setUrlParams( { conversation: id } );
	};

	const send = async () => {
		const value = text.trim();
		if ( ! value || sending ) {
			return;
		}
		setSending( true );
		setSendError( null );
		setMessages( ( m ) => [
			...m,
			{ id: 'pending', role: 'user', content: value, created_at: '' },
		] );
		try {
			const res = await apiPost( 'chat/messages', {
				conversation_id: activeId,
				text: value,
			} );
			setMessages( ( m ) => [
				...m.filter( ( x ) => x.id !== 'pending' ),
				...res.messages,
			] );
			setText( '' );
			setUsage( res.ai_usage || usage );
			setAnnounce( __( 'Claude answered.', 'seo-console' ) );
			if ( res.conversation_id !== activeId ) {
				setActiveId( res.conversation_id );
				setUrlParams( { conversation: res.conversation_id } );
			}
			list.reload();
		} catch ( e ) {
			setMessages( ( m ) => m.filter( ( x ) => x.id !== 'pending' ) );
			const err = normaliseError( e );
			setSendError( err );
			setAnnounce( err.message );
		}
		setSending( false );
	};

	const onKeyDown = ( event ) => {
		if (
			event.key === 'Enter' &&
			! event.shiftKey &&
			! event.nativeEvent.isComposing
		) {
			event.preventDefault();
			send();
		}
	};

	const remove = async ( id ) => {
		try {
			await removeConversation( id );
			if ( id === activeId ) {
				open( '' );
				setMessages( [] );
			}
			list.reload();
		} catch ( e ) {
			setSendError( normaliseError( e ) );
		}
	};

	const aiReady = list.data
		? !! list.data.ai_configured
		: config.aiConfigured;
	const conversations = list.data ? list.data.items : [];
	const lastQuestion = [ ...messages ]
		.reverse()
		.find( ( m ) => m.role === 'user' );

	if ( ! aiReady && ! list.loading ) {
		return (
			<Page title={ __( 'AI Chat', 'seo-console' ) }>
				<Card>
					<EmptyState
						icon="admin-network"
						title={ __(
							'Connect Claude to start chatting',
							'seo-console'
						) }
						actions={
							<Button
								variant="primary"
								href={ adminPageUrl( 'settings' ) }
							>
								{ __( 'Open Settings', 'seo-console' ) }
							</Button>
						}
					>
						{ __(
							'Add your Claude API key (or raise this month’s AI budget) in Settings. The chat then knows your site, plugins and latest reports.',
							'seo-console'
						) }
					</EmptyState>
				</Card>
			</Page>
		);
	}

	return (
		<Page
			title={ __( 'AI Chat', 'seo-console' ) }
			description={ __(
				'Ask about your site’s SEO and speed. Claude knows your setup and the latest SEO Console reports. It only explains and proposes; changes still go through Suggestions, where you approve them.',
				'seo-console'
			) }
			actions={
				<Button variant="primary" onClick={ () => open( '' ) }>
					{ __( 'New conversation', 'seo-console' ) }
				</Button>
			}
		>
			<LiveRegion>{ announce }</LiveRegion>
			<div className="seoc-chat">
				<nav
					className="seoc-chat__list"
					aria-label={ __( 'Conversations', 'seo-console' ) }
				>
					<h2 className="seoc-chat__heading">
						{ __( 'Conversations', 'seo-console' ) }
					</h2>
					<ErrorNotice error={ list.error } onRetry={ list.reload } />
					{ list.loading && ! list.data && <Spinner /> }
					{ list.data && ! conversations.length && (
						<p className="seoc-muted">
							{ __( 'No conversations yet.', 'seo-console' ) }
						</p>
					) }
					<ul>
						{ conversations.map( ( c ) => (
							<li
								key={ c.id }
								className={
									c.id === activeId ? 'is-active' : ''
								}
							>
								<Button
									variant="link"
									onClick={ () => open( c.id ) }
									aria-current={
										c.id === activeId ? 'true' : undefined
									}
								>
									{ c.title ||
										__( '(untitled)', 'seo-console' ) }
								</Button>
								<div className="seoc-muted seoc-small">
									<span title={ formatDate( c.updated_at ) }>
										{ formatRelative( c.updated_at ) }
									</span>
									<ConfirmButton
										variant="link"
										isDestructive
										message={ __(
											'Delete this conversation? This cannot be undone.',
											'seo-console'
										) }
										confirmLabel={ __(
											'Delete',
											'seo-console'
										) }
										onConfirm={ () => remove( c.id ) }
									>
										{ __( 'Delete', 'seo-console' ) }
									</ConfirmButton>
								</div>
							</li>
						) ) }
					</ul>
				</nav>

				<section
					className="seoc-chat__main"
					aria-label={ __( 'Conversation', 'seo-console' ) }
				>
					<ErrorNotice
						error={ thread.error }
						onRetry={ thread.reload }
					/>
					{ thread.loading && activeId && ! messages.length && (
						<Loading>
							{ __( 'Loading conversation…', 'seo-console' ) }
						</Loading>
					) }
					{ ! messages.length && ! thread.loading && (
						<EmptyState
							icon="format-chat"
							title={ __(
								'Ask anything about your site',
								'seo-console'
							) }
						>
							{ __(
								'For example: “Why is my home page slow on mobile?”, “Which pages should I fix first?” or “Write a better meta description for my contact page.”',
								'seo-console'
							) }
						</EmptyState>
					) }
					<ol className="seoc-chat__thread" aria-live="off">
						{ messages.map( ( m ) => (
							<Message key={ m.id } message={ m } />
						) ) }
						{ sending && (
							<li className="seoc-chat__message seoc-chat__message--assistant">
								<div className="seoc-chat__bubble seoc-muted">
									<Spinner />{ ' ' }
									{ __(
										'Claude is thinking…',
										'seo-console'
									) }
								</div>
							</li>
						) }
					</ol>
					<div ref={ threadEnd } />

					<ErrorNotice error={ sendError } />
					<div className="seoc-chat__composer">
						<label
							htmlFor="seoc-chat-input"
							className="screen-reader-text"
						>
							{ __( 'Your message', 'seo-console' ) }
						</label>
						<textarea
							id="seoc-chat-input"
							value={ text }
							rows={ 3 }
							maxLength={ MAX_CHARS }
							placeholder={ __(
								'Ask Claude… (Enter to send, Shift+Enter for a new line)',
								'seo-console'
							) }
							onChange={ ( e ) => setText( e.target.value ) }
							onKeyDown={ onKeyDown }
							disabled={ sending }
						/>
						<Button
							variant="primary"
							onClick={ send }
							isBusy={ sending }
							disabled={ sending || ! text.trim() }
						>
							{ sending
								? __( 'Sending…', 'seo-console' )
								: __( 'Send', 'seo-console' ) }
						</Button>
					</div>
					<CostNote usage={ usage } />
				</section>
			</div>

			<TaskPanel
				conversationId={ activeId }
				defaultGoal={ lastQuestion ? lastQuestion.content : '' }
			/>
		</Page>
	);
}

mount( App );
