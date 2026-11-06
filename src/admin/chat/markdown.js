/**
 * A small, safe Markdown subset for chat answers: paragraphs, headings, bullet and numbered lists, fenced code
 * blocks, inline code, bold, italic and links (http/https/mailto only, opened with rel="noopener noreferrer").
 *
 * parseMarkdown() is pure (tested in tests/js/chat-markdown.test.js); Markdown renders the tree as React
 * elements. No HTML from the text is ever interpreted: everything else stays plain text.
 */
import { Fragment } from '@wordpress/element';

const SAFE_URL = /^(https?:\/\/|mailto:)[^\s<>"']+$/i;

/**
 * Inline tokens of one line of text.
 *
 * @param {string} text Text.
 * @return {Array} Nodes: { type: 'text'|'code'|'strong'|'em'|'link', value?, children?, href? }.
 */
export function parseInline( text ) {
	const nodes = [];
	const re =
		/(`+)([^`]+?)\1|\*\*(.+?)\*\*|__(.+?)__|\[([^\]\n]+)\]\(([^)\s]+)\)|\*(?!\s)([^*\n]+?)\*(?!\w)|_(?!\s)([^_\n]+?)_(?!\w)|(https?:\/\/[^\s<>()]+[^\s<>().,;:!?'"])/g;
	let last = 0;
	let m;
	while ( ( m = re.exec( text ) ) !== null ) {
		// Emphasis must not start inside a word (snake_case, 2*3*4). Checked here instead of a lookbehind,
		// which older Safari versions cannot parse.
		if (
			( m[ 7 ] !== undefined || m[ 8 ] !== undefined ) &&
			m.index > 0 &&
			/[\w*_]/.test( text[ m.index - 1 ] )
		) {
			re.lastIndex = m.index + 1;
			continue;
		}
		if ( m.index > last ) {
			nodes.push( { type: 'text', value: text.slice( last, m.index ) } );
		}
		if ( m[ 2 ] !== undefined ) {
			nodes.push( { type: 'code', value: m[ 2 ] } );
		} else if ( m[ 3 ] !== undefined || m[ 4 ] !== undefined ) {
			nodes.push( {
				type: 'strong',
				children: parseInline( m[ 3 ] ?? m[ 4 ] ),
			} );
		} else if ( m[ 5 ] !== undefined ) {
			const href = m[ 6 ];
			if ( SAFE_URL.test( href ) ) {
				nodes.push( {
					type: 'link',
					href,
					children: parseInline( m[ 5 ] ).filter(
						( n ) => n.type !== 'link'
					),
				} );
			} else {
				nodes.push( { type: 'text', value: m[ 0 ] } );
			}
		} else if ( m[ 7 ] !== undefined || m[ 8 ] !== undefined ) {
			nodes.push( {
				type: 'em',
				children: parseInline( m[ 7 ] ?? m[ 8 ] ),
			} );
		} else if ( m[ 9 ] !== undefined ) {
			nodes.push( {
				type: 'link',
				href: m[ 9 ],
				children: [ { type: 'text', value: m[ 9 ] } ],
			} );
		}
		last = re.lastIndex;
	}
	if ( last < text.length ) {
		nodes.push( { type: 'text', value: text.slice( last ) } );
	}
	return nodes;
}

/**
 * Block structure.
 *
 * @param {string} source Markdown.
 * @return {Array} Blocks: { type: 'p'|'heading'|'ul'|'ol'|'code', level?, lang?, value?, inline?, items? }.
 */
export function parseMarkdown( source ) {
	const lines = String( source ?? '' )
		.replace( /\r\n?/g, '\n' )
		.split( '\n' );
	const blocks = [];
	let para = [];
	let list = null;

	const flushPara = () => {
		if ( para.length ) {
			blocks.push( {
				type: 'p',
				lines: para.map( ( l ) => parseInline( l ) ),
			} );
			para = [];
		}
	};
	const flushList = () => {
		if ( list ) {
			blocks.push( list );
			list = null;
		}
	};

	for ( let i = 0; i < lines.length; i++ ) {
		const line = lines[ i ];
		const fence = line.match( /^\s*(```|~~~)\s*([\w+-]*)\s*$/ );
		if ( fence ) {
			flushPara();
			flushList();
			const code = [];
			i++;
			while (
				i < lines.length &&
				! new RegExp( '^\\s*' + fence[ 1 ] + '\\s*$' ).test(
					lines[ i ]
				)
			) {
				code.push( lines[ i ] );
				i++;
			}
			blocks.push( {
				type: 'code',
				lang: fence[ 2 ],
				value: code.join( '\n' ),
			} );
			continue;
		}
		const heading = line.match( /^\s*(#{1,6})\s+(.+?)\s*#*\s*$/ );
		if ( heading ) {
			flushPara();
			flushList();
			blocks.push( {
				type: 'heading',
				level: heading[ 1 ].length,
				inline: parseInline( heading[ 2 ] ),
			} );
			continue;
		}
		const bullet = line.match( /^\s*[-*+]\s+(.*)$/ );
		const numbered = line.match( /^\s*(\d{1,3})[.)]\s+(.*)$/ );
		if ( bullet || numbered ) {
			flushPara();
			const type = bullet ? 'ul' : 'ol';
			if ( ! list || list.type !== type ) {
				flushList();
				list = { type, items: [] };
				if ( numbered ) {
					list.start = parseInt( numbered[ 1 ], 10 );
				}
			}
			list.items.push(
				parseInline( bullet ? bullet[ 1 ] : numbered[ 2 ] )
			);
			continue;
		}
		if ( ! line.trim() ) {
			flushPara();
			flushList();
			continue;
		}
		if ( list && /^\s{2,}\S/.test( line ) ) {
			// Continuation of the previous list item.
			const item = list.items[ list.items.length - 1 ];
			item.push(
				{ type: 'text', value: ' ' },
				...parseInline( line.trim() )
			);
			continue;
		}
		flushList();
		para.push( line );
	}
	flushPara();
	flushList();
	return blocks;
}

function Inline( { nodes } ) {
	return nodes.map( ( n, i ) => {
		switch ( n.type ) {
			case 'code':
				return <code key={ i }>{ n.value }</code>;
			case 'strong':
				return (
					<strong key={ i }>
						<Inline nodes={ n.children } />
					</strong>
				);
			case 'em':
				return (
					<em key={ i }>
						<Inline nodes={ n.children } />
					</em>
				);
			case 'link':
				return (
					<a
						key={ i }
						href={ n.href }
						target="_blank"
						rel="noopener noreferrer"
					>
						<Inline nodes={ n.children } />
					</a>
				);
			default:
				return <Fragment key={ i }>{ n.value }</Fragment>;
		}
	} );
}

/**
 * Renders Markdown text as React elements.
 *
 * @param {Object} props
 * @param {string} props.text Markdown.
 * @return {JSX.Element} Content.
 */
export default function Markdown( { text } ) {
	const blocks = parseMarkdown( text );
	return (
		<div className="seoc-markdown">
			{ blocks.map( ( b, i ) => {
				switch ( b.type ) {
					case 'heading': {
						// Answers sit under the page's h1/h2: map to h3–h6.
						const Tag = `h${ Math.min( 6, b.level + 2 ) }`;
						return (
							<Tag key={ i }>
								<Inline nodes={ b.inline } />
							</Tag>
						);
					}
					case 'code':
						return (
							<pre key={ i }>
								<code>{ b.value }</code>
							</pre>
						);
					case 'ul':
						return (
							<ul key={ i }>
								{ b.items.map( ( item, j ) => (
									<li key={ j }>
										<Inline nodes={ item } />
									</li>
								) ) }
							</ul>
						);
					case 'ol':
						return (
							<ol key={ i } start={ b.start }>
								{ b.items.map( ( item, j ) => (
									<li key={ j }>
										<Inline nodes={ item } />
									</li>
								) ) }
							</ol>
						);
					default:
						return (
							<p key={ i }>
								{ b.lines.map( ( line, j ) => (
									<Fragment key={ j }>
										{ j > 0 && <br /> }
										<Inline nodes={ line } />
									</Fragment>
								) ) }
							</p>
						);
				}
			} ) }
		</div>
	);
}
