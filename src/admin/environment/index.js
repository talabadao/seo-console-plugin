/**
 * Environment page: hosting and WordPress checks with plain-English advice.
 */
import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import {
	Card,
	ErrorNotice,
	Loading,
	Page,
	SourceLine,
	StatusBadge,
	apiGet,
	formatDate,
	mount,
	useAsync,
} from '../shared';

const ORDER = { poor: 0, 'needs-work': 1, unknown: 2, good: 3 };

function Summary( { checks } ) {
	const counts = { good: 0, 'needs-work': 0, poor: 0, unknown: 0 };
	checks.forEach( ( c ) => {
		counts[ c.status in counts ? c.status : 'unknown' ] += 1;
	} );
	return (
		<p>
			{ [ 'poor', 'needs-work', 'good', 'unknown' ]
				.filter( ( s ) => counts[ s ] > 0 )
				.map( ( s ) => (
					<span key={ s }>
						<StatusBadge
							status={ s }
							label={ sprintf(
								/* translators: 1: number of checks, 2: status word */
								__( '%1$d × %2$s', 'seo-console' ),
								counts[ s ],
								{
									good: __( 'Good', 'seo-console' ),
									'needs-work': __(
										'Needs work',
										'seo-console'
									),
									poor: __( 'Poor', 'seo-console' ),
									unknown: __( 'Unknown', 'seo-console' ),
								}[ s ]
							) }
						/>{ ' ' }
					</span>
				) ) }
		</p>
	);
}

function App() {
	const env = useAsync(
		( signal ) => apiGet( 'environment', {}, signal ),
		[]
	);
	const checks = env.data
		? [ ...env.data.checks ].sort(
				( a, b ) =>
					( ORDER[ a.status ] ?? 2 ) - ( ORDER[ b.status ] ?? 2 )
		  )
		: [];
	return (
		<Page
			title={ __( 'Environment', 'seo-console' ) }
			description={ __(
				'Your hosting and WordPress setup, checked for speed and reliability. Each item says what to do in plain words.',
				'seo-console'
			) }
			actions={
				<Button
					variant="secondary"
					isBusy={ env.loading }
					disabled={ env.loading }
					onClick={ env.reload }
				>
					{ __( 'Check again', 'seo-console' ) }
				</Button>
			}
		>
			{ env.loading && ! env.data && (
				<Loading>
					{ __(
						'Checking the server (this loads your home page 3 times)…',
						'seo-console'
					) }
				</Loading>
			) }
			<ErrorNotice error={ env.error } onRetry={ env.reload } />
			{ env.data && (
				<Card
					title={ __( 'Checks', 'seo-console' ) }
					description={ __(
						'Problems first. "Unknown" means the host does not let WordPress read that value.',
						'seo-console'
					) }
				>
					<Summary checks={ checks } />
					<div className="seoc-table-scroll">
						<table className="widefat striped seoc-table">
							<thead>
								<tr>
									<th scope="col">
										{ __( 'Check', 'seo-console' ) }
									</th>
									<th scope="col">
										{ __( 'Value', 'seo-console' ) }
									</th>
									<th scope="col">
										{ __( 'Status', 'seo-console' ) }
									</th>
									<th scope="col">
										{ __( 'What to do', 'seo-console' ) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ checks.map( ( c ) => (
									<tr key={ c.id }>
										<th scope="row">{ c.label }</th>
										<td>{ c.value }</td>
										<td>
											<StatusBadge status={ c.status } />
										</td>
										<td>{ c.description }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
					<SourceLine>
						{ sprintf(
							/* translators: %s: date and time */
							__( 'Checked %s.', 'seo-console' ),
							formatDate( env.data.generated_at )
						) }
					</SourceLine>
				</Card>
			) }
		</Page>
	);
}

mount( App );
