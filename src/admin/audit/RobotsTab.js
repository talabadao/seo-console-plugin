/**
 * robots.txt: content, who manages it, checks with advice, parsed groups and a URL tester.
 */
import { Button, SelectControl, TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	Card,
	EmptyState,
	ErrorNotice,
	Loading,
	StatusBadge,
	formatDate,
	normaliseError,
	useAsync,
} from '../shared';
import { getRobots, testRobots } from './api';
import Checks from './Checks';

function Tester() {
	const [ url, setUrl ] = useState( '/' );
	const [ agent, setAgent ] = useState( 'Googlebot' );
	const [ result, setResult ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const run = async ( event ) => {
		event.preventDefault();
		setBusy( true );
		setError( null );
		try {
			setResult( await testRobots( url, agent ) );
		} catch ( e ) {
			setResult( null );
			setError( normaliseError( e ) );
		}
		setBusy( false );
	};

	return (
		<Card
			title={ __( 'Test an address', 'seo-console' ) }
			description={ __(
				'Check whether search engines may crawl a page. Nothing is requested from your site.',
				'seo-console'
			) }
			headingLevel={ 3 }
		>
			<form className="seoc-toolbar" onSubmit={ run }>
				<TextControl
					__nextHasNoMarginBottom
					label={ __( 'Address or path', 'seo-console' ) }
					value={ url }
					onChange={ setUrl }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Search engine robot', 'seo-console' ) }
					value={ agent }
					options={ [
						{ value: 'Googlebot', label: 'Googlebot' },
						{ value: 'Googlebot-Image', label: 'Googlebot-Image' },
						{ value: 'Bingbot', label: 'Bingbot' },
						{
							value: '*',
							label: __( 'Any other robot (*)', 'seo-console' ),
						},
					] }
					onChange={ setAgent }
				/>
				<Button
					variant="secondary"
					type="submit"
					isBusy={ busy }
					disabled={ busy || ! url }
				>
					{ __( 'Test', 'seo-console' ) }
				</Button>
			</form>
			{ error && <ErrorNotice error={ error } /> }
			{ result && (
				<p aria-live="polite">
					{ result.allowed ? (
						<StatusBadge
							status="good"
							label={ __( 'Allowed', 'seo-console' ) }
						/>
					) : (
						<StatusBadge
							status="poor"
							label={ __( 'Blocked', 'seo-console' ) }
						/>
					) }{ ' ' }
					<code>{ result.path }</code>{ ' ' }
					{ result.rule ? (
						<span>
							{ sprintf(
								/* translators: 1: rule, 2: line number */
								__(
									'because of "%1$s" (line %2$d)',
									'seo-console'
								),
								`${ result.rule.type }: ${ result.rule.pattern }`,
								result.rule.line
							) }
						</span>
					) : (
						<span className="seoc-muted">
							{ __( '(no rule matches)', 'seo-console' ) }
						</span>
					) }
				</p>
			) }
		</Card>
	);
}

/**
 * @return {JSX.Element} Tab.
 */
export default function RobotsTab() {
	const [ fresh, setFresh ] = useState( false );
	const data = useAsync(
		( signal ) => getRobots( fresh ? { fresh: 1 } : {}, signal ),
		[ fresh ]
	);
	if ( data.error ) {
		return <ErrorNotice error={ data.error } onRetry={ data.reload } />;
	}
	if ( ! data.data ) {
		return <Loading />;
	}
	const { robots, checks } = data.data;
	return (
		<div className="seoc-audit-robots">
			<Card
				title={ __( 'Your robots.txt', 'seo-console' ) }
				description={ robots.advice }
				actions={
					<>
						{ robots.edit_url && (
							<a href={ robots.edit_url }>
								{ sprintf(
									/* translators: %s: plugin or "WordPress" */
									__( 'Edit in %s', 'seo-console' ),
									robots.manager_label
								) }
							</a>
						) }{ ' ' }
						<Button
							variant="secondary"
							size="compact"
							onClick={ () =>
								fresh ? data.reload() : setFresh( true )
							}
							isBusy={ data.loading }
						>
							{ __( 'Check again now', 'seo-console' ) }
						</Button>
					</>
				}
			>
				<p className="seoc-muted seoc-small">
					{ sprintf(
						/* translators: %s: who manages robots.txt */
						__( 'Managed by: %s.', 'seo-console' ),
						robots.manager_label
					) }{ ' ' }
					{ data.data.live
						? __( 'Checked just now.', 'seo-console' )
						: sprintf(
								/* translators: %s: date */
								__( 'From the audit of %s.', 'seo-console' ),
								formatDate( data.data.analysed_at )
						  ) }{ ' ' }
					<a href={ robots.url } target="_blank" rel="noreferrer">
						{ __( 'Open robots.txt', 'seo-console' ) }
					</a>
				</p>
				{ robots.exists ? (
					<pre className="seoc-audit-code" tabIndex={ 0 }>
						{ robots.content }
					</pre>
				) : (
					<EmptyState
						title={ __( 'No robots.txt found', 'seo-console' ) }
					>
						{ sprintf(
							/* translators: %d: HTTP status code */
							__(
								'Your site answered with status %d.',
								'seo-console'
							),
							robots.status
						) }
					</EmptyState>
				) }
			</Card>

			<Checks
				checks={ checks }
				emptyText={ __(
					'No problems found in robots.txt.',
					'seo-console'
				) }
			/>

			<Tester />

			{ robots.groups.length > 0 && (
				<Card
					title={ __( 'Rules per robot', 'seo-console' ) }
					headingLevel={ 3 }
					flush
				>
					<div className="seoc-table-scroll">
						<table className="widefat striped seoc-table">
							<thead>
								<tr>
									<th scope="col">
										{ __( 'Robots', 'seo-console' ) }
									</th>
									<th scope="col">
										{ __( 'Rules', 'seo-console' ) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ robots.groups.map( ( group, i ) => (
									<tr key={ i }>
										<td>{ group.agents.join( ', ' ) }</td>
										<td>
											{ group.rules.length ? (
												<ul className="seoc-plain-list">
													{ group.rules.map(
														( rule, j ) => (
															<li key={ j }>
																<code>{ `${ rule.type }: ${ rule.pattern }` }</code>
															</li>
														)
													) }
												</ul>
											) : (
												__(
													'Everything allowed',
													'seo-console'
												)
											) }
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				</Card>
			) }
		</div>
	);
}
