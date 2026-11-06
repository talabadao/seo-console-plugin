/**
 * Renders the facts stored with an issue (issue.data) in plain words.
 */
import { __, sprintf } from '@wordpress/i18n';

import { formatNumber, shorten } from '../shared';

function UrlList( { urls, label } ) {
	if ( ! Array.isArray( urls ) || ! urls.length ) {
		return null;
	}
	return (
		<div className="seoc-small">
			{ label && <span className="seoc-muted">{ label } </span> }
			<ul className="seoc-plain-list seoc-audit-urls">
				{ urls.map( ( item, i ) => {
					const url = typeof item === 'string' ? item : item.url;
					return (
						<li key={ `${ url }-${ i }` }>
							{ url ? (
								<a
									href={ url }
									target="_blank"
									rel="noreferrer"
								>
									{ shorten( url, 90 ) }
								</a>
							) : (
								__( '(no address)', 'seo-console' )
							) }
							{ typeof item === 'object' && item.anchor && (
								<span className="seoc-muted">
									{ ' — ' }
									{ sprintf(
										/* translators: %s: link text */
										__( 'link text "%s"', 'seo-console' ),
										shorten( item.anchor, 50 )
									) }
								</span>
							) }
							{ typeof item === 'object' && item.status ? (
								<span className="seoc-tag">
									{ item.status }
								</span>
							) : null }
							{ typeof item === 'object' && item.final_url && (
								<span className="seoc-muted">
									{ ' → ' }
									{ shorten( item.final_url, 70 ) }
								</span>
							) }
						</li>
					);
				} ) }
			</ul>
		</div>
	);
}

/**
 * @param {Object} props
 * @param {Object} props.data Issue data.
 * @return {JSX.Element|null} Details.
 */
export default function IssueData( { data } ) {
	if ( ! data || typeof data !== 'object' ) {
		return null;
	}
	const parts = [];
	const add = ( key, node ) => parts.push( <div key={ key }>{ node }</div> );

	if ( data.length !== undefined ) {
		add(
			'length',
			sprintf(
				/* translators: %s: number of characters */
				__( '%s characters', 'seo-console' ),
				formatNumber( data.length )
			)
		);
	}
	if ( data.words !== undefined ) {
		add(
			'words',
			sprintf(
				/* translators: 1: words on the page, 2: minimum */
				__( '%1$s words (aim for at least %2$s)', 'seo-console' ),
				formatNumber( data.words ),
				formatNumber( data.minimum )
			)
		);
	}
	if ( data.status ) {
		add(
			'status',
			sprintf(
				/* translators: %s: HTTP status code */
				__( 'Status code %s', 'seo-console' ),
				data.status
			)
		);
	}
	if ( data.ms !== undefined ) {
		add(
			'ms',
			sprintf(
				/* translators: %s: seconds */
				__( 'Server answered in %s s', 'seo-console' ),
				formatNumber( data.ms / 1000, { maximumFractionDigits: 1 } )
			)
		);
	}
	if ( data.bytes !== undefined ) {
		add(
			'bytes',
			sprintf(
				/* translators: %s: size in MB */
				__( 'HTML size %s MB', 'seo-console' ),
				formatNumber( data.bytes / 1048576, {
					maximumFractionDigits: 1,
				} )
			)
		);
	}
	if ( data.depth !== undefined ) {
		add(
			'depth',
			sprintf(
				/* translators: %s: number of clicks */
				__( '%s clicks from the home page', 'seo-console' ),
				data.depth
			)
		);
	}
	if ( data.canonical ) {
		add(
			'canonical',
			<>
				{ __( 'Canonical:', 'seo-console' ) }{ ' ' }
				<code>{ data.canonical }</code>
			</>
		);
	}
	if ( Array.isArray( data.chain ) && data.chain.length ) {
		add(
			'chain',
			<>
				{ __( 'Redirects:', 'seo-console' ) }{ ' ' }
				{ data.chain
					.map( ( hop ) => `${ hop.status } ${ hop.url }` )
					.join( ' → ' ) }
				{ data.final_url ? ` → ${ data.final_url }` : '' }
			</>
		);
	}
	if ( Array.isArray( data.codes ) ) {
		add( 'codes', data.codes.join( ', ' ) );
	}
	if ( data.error ) {
		add( 'error', <code>{ data.error }</code> );
	}
	if ( data.robots ) {
		add( 'robots', <code>{ data.robots }</code> );
	}
	if ( data.rule && data.rule.pattern ) {
		add(
			'rule',
			<>
				{ __( 'robots.txt rule:', 'seo-console' ) }{ ' ' }
				<code>{ `${ data.rule.type }: ${ data.rule.pattern }` }</code>
			</>
		);
	}
	if ( data.links ) {
		parts.push(
			<UrlList
				key="links"
				urls={ data.links }
				label={ __( 'Links on this page:', 'seo-console' ) }
			/>
		);
	}
	if ( data.images ) {
		parts.push(
			<UrlList
				key="images"
				urls={ data.images }
				label={ __( 'Images:', 'seo-console' ) }
			/>
		);
	}
	if ( data.others ) {
		parts.push(
			<UrlList
				key="others"
				urls={ data.others }
				label={ __( 'Same text on:', 'seo-console' ) }
			/>
		);
	}
	if ( data.pages ) {
		parts.push(
			<UrlList
				key="pages"
				urls={ data.pages }
				label={ __( 'Linked from:', 'seo-console' ) }
			/>
		);
	}
	if ( data.urls ) {
		parts.push( <UrlList key="urls" urls={ data.urls } /> );
	}
	if ( data.assets ) {
		parts.push( <UrlList key="assets" urls={ data.assets } /> );
	}
	if ( data.files ) {
		parts.push(
			<UrlList
				key="files"
				urls={ data.files.map( ( f ) => ( {
					url: f.sitemap,
					anchor: f.problem,
				} ) ) }
			/>
		);
	}
	if ( Array.isArray( data.errors ) ) {
		parts.push(
			<ul key="errors" className="seoc-plain-list seoc-small">
				{ data.errors.map( ( e, i ) => (
					<li key={ i }>
						{ sprintf(
							/* translators: %d: line number */
							__( 'Line %d:', 'seo-console' ),
							e.line
						) }{ ' ' }
						<code>{ e.text }</code>
					</li>
				) ) }
			</ul>
		);
	}
	if ( data.advice ) {
		add( 'advice', <em>{ data.advice }</em> );
	}
	return parts.length ? (
		<div className="seoc-audit-data seoc-small">{ parts }</div>
	) : null;
}
