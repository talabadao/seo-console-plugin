/**
 * Issues grouped by rule; each group expands to the affected URLs.
 */
import { Button, SelectControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import {
	EmptyState,
	ErrorNotice,
	Loading,
	Pagination,
	StatusBadge,
	formatNumber,
	getUrlParams,
	setUrlParams,
	shorten,
	useAsync,
} from '../shared';
import { getIssueGroups, getIssues } from './api';
import IssueData from './IssueData';
import {
	SEVERITY_STATUS,
	categoryLabel,
	fixableLabel,
	severityLabel,
} from './labels';

const PER_PAGE = 20;

function IssueRows( { runId, ruleId } ) {
	const [ page, setPage ] = useState( 1 );
	const list = useAsync(
		( signal ) =>
			getIssues(
				runId,
				{ rule_id: ruleId, page, per_page: PER_PAGE },
				signal
			),
		[ runId, ruleId, page ]
	);
	if ( list.error ) {
		return <ErrorNotice error={ list.error } onRetry={ list.reload } />;
	}
	if ( ! list.data ) {
		return <Loading />;
	}
	return (
		<>
			<div className="seoc-table-scroll">
				<table className="widefat striped seoc-table">
					<thead>
						<tr>
							<th scope="col">{ __( 'Page', 'seo-console' ) }</th>
							<th scope="col">
								{ __( 'Details', 'seo-console' ) }
							</th>
							<th scope="col">
								<span className="screen-reader-text">
									{ __( 'Actions', 'seo-console' ) }
								</span>
							</th>
						</tr>
					</thead>
					<tbody>
						{ list.data.items.map( ( issue ) => (
							<tr key={ issue.id }>
								<td>
									{ issue.url ? (
										<a
											href={ issue.url }
											target="_blank"
											rel="noreferrer"
										>
											{ shorten( issue.url, 70 ) }
										</a>
									) : (
										<span className="seoc-muted">
											{ __(
												'Whole site',
												'seo-console'
											) }
										</span>
									) }
								</td>
								<td>
									<IssueData data={ issue.data } />
								</td>
								<td className="seoc-table__actions">
									{ issue.edit_link && (
										<a href={ issue.edit_link }>
											{ __( 'Edit', 'seo-console' ) }
										</a>
									) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
			<Pagination
				page={ page }
				perPage={ PER_PAGE }
				total={ list.data.total }
				onChange={ setPage }
				disabled={ list.loading }
			/>
		</>
	);
}

function IssueGroup( { runId, group, open, onToggle } ) {
	return (
		<li className="seoc-audit-group">
			<div className="seoc-audit-group__head">
				<StatusBadge
					status={ SEVERITY_STATUS[ group.severity ] }
					label={ severityLabel( group.severity ) }
				/>
				<strong>{ group.title }</strong>
				<span className="seoc-muted">
					{ group.scope === 'run'
						? __( 'whole site', 'seo-console' )
						: sprintf(
								/* translators: %s: number of pages */
								_n(
									'%s page',
									'%s pages',
									group.count,
									'seo-console'
								),
								formatNumber( group.count )
						  ) }
				</span>
				<span className="seoc-tag">
					{ categoryLabel( group.category ) }
				</span>
				<Button
					variant="link"
					aria-expanded={ open }
					onClick={ onToggle }
				>
					{ open
						? __( 'Hide details', 'seo-console' )
						: __( 'Show details', 'seo-console' ) }
				</Button>
			</div>
			{ open && (
				<div className="seoc-audit-group__body">
					<p>
						<strong>
							{ __( 'Why it matters:', 'seo-console' ) }
						</strong>{ ' ' }
						{ group.why }
					</p>
					<p>
						<strong>{ __( 'How to fix:', 'seo-console' ) }</strong>{ ' ' }
						{ group.how_to_fix }
					</p>
					<p className="seoc-muted">
						{ fixableLabel( group.fixable_by ) }
					</p>
					<IssueRows runId={ runId } ruleId={ group.rule_id } />
				</div>
			) }
		</li>
	);
}

/**
 * @param {Object} props
 * @param {number} props.runId Run id.
 * @return {JSX.Element} Tab.
 */
export default function IssuesTab( { runId } ) {
	const params = getUrlParams();
	const [ severity, setSeverity ] = useState(
		params.get( 'severity' ) || ''
	);
	const [ category, setCategory ] = useState(
		params.get( 'category' ) || ''
	);
	const [ openRule, setOpenRule ] = useState( params.get( 'rule' ) || '' );
	const groups = useAsync(
		( signal ) => getIssueGroups( runId, { severity, category }, signal ),
		[ runId, severity, category ]
	);

	const toggle = ( ruleId ) => {
		const next = openRule === ruleId ? '' : ruleId;
		setOpenRule( next );
		setUrlParams( { rule: next } );
	};

	return (
		<div className="seoc-audit-issues">
			<div className="seoc-toolbar">
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Severity', 'seo-console' ) }
					value={ severity }
					options={ [
						{ value: '', label: __( 'All', 'seo-console' ) },
						{ value: 'error', label: severityLabel( 'error' ) },
						{ value: 'warning', label: severityLabel( 'warning' ) },
						{ value: 'notice', label: severityLabel( 'notice' ) },
					] }
					onChange={ ( v ) => {
						setSeverity( v );
						setUrlParams( { severity: v } );
					} }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Area', 'seo-console' ) }
					value={ category }
					options={ [
						{ value: '', label: __( 'All', 'seo-console' ) },
						...[
							'indexability',
							'content',
							'links',
							'images',
							'performance',
							'international',
							'social',
							'structured-data',
						].map( ( c ) => ( {
							value: c,
							label: categoryLabel( c ),
						} ) ),
					] }
					onChange={ ( v ) => {
						setCategory( v );
						setUrlParams( { category: v } );
					} }
				/>
			</div>
			{ groups.error && (
				<ErrorNotice error={ groups.error } onRetry={ groups.reload } />
			) }
			{ ! groups.data && ! groups.error && <Loading /> }
			{ groups.data && ! groups.data.groups.length && (
				<EmptyState
					title={ __( 'No issues here', 'seo-console' ) }
					icon="yes-alt"
				>
					{ __(
						'Nothing matches these filters. Well done!',
						'seo-console'
					) }
				</EmptyState>
			) }
			{ groups.data && groups.data.groups.length > 0 && (
				<ul className="seoc-plain-list seoc-audit-groups">
					{ groups.data.groups.map( ( group ) => (
						<IssueGroup
							key={ group.rule_id }
							runId={ runId }
							group={ group }
							open={ openRule === group.rule_id }
							onToggle={ () => toggle( group.rule_id ) }
						/>
					) ) }
				</ul>
			) }
		</div>
	);
}
