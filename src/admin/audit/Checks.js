/**
 * Site-wide check results (robots.txt / sitemap) with why + how to fix.
 */
import { __ } from '@wordpress/i18n';

import { Card, StatusBadge } from '../shared';
import IssueData from './IssueData';
import { SEVERITY_STATUS, fixableLabel, severityLabel } from './labels';

/**
 * @param {Object} props
 * @param {Array}  props.checks    Check items from the REST API.
 * @param {string} props.emptyText Text when there are none.
 * @return {JSX.Element} Card.
 */
export default function Checks( { checks, emptyText } ) {
	return (
		<Card
			title={ __( 'Checks', 'seo-console' ) }
			headingLevel={ 3 }
			tone={
				checks.some( ( c ) => c.severity === 'error' )
					? 'attention'
					: undefined
			}
		>
			{ checks.length ? (
				<ul className="seoc-plain-list seoc-audit-checks">
					{ checks.map( ( check, i ) => (
						<li key={ `${ check.rule_id }-${ i }` }>
							<StatusBadge
								status={ SEVERITY_STATUS[ check.severity ] }
								label={ severityLabel( check.severity ) }
							/>{ ' ' }
							<strong>{ check.title }</strong>
							<p>{ check.why }</p>
							<p>
								<strong>
									{ __( 'How to fix:', 'seo-console' ) }
								</strong>{ ' ' }
								{ check.how_to_fix }{ ' ' }
								<span className="seoc-muted">
									({ fixableLabel( check.fixable_by ) })
								</span>
							</p>
							<IssueData data={ check.data } />
						</li>
					) ) }
				</ul>
			) : (
				<p>
					<StatusBadge
						status="good"
						label={ __( 'All good', 'seo-console' ) }
					/>{ ' ' }
					{ emptyText }
				</p>
			) }
		</Card>
	);
}
