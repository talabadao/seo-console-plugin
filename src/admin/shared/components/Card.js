/**
 * Card with title, optional description, header actions and footer (wraps @wordpress/components Card).
 */
import {
	Card as WPCard,
	CardBody,
	CardFooter,
	CardHeader,
} from '@wordpress/components';

/**
 * @param {Object}  props
 * @param {Node}    props.title        Card title (rendered as h2 by default).
 * @param {number}  props.headingLevel Heading level for the title (2–4).
 * @param {Node}    props.description  One line under the title.
 * @param {Node}    props.actions      Buttons/links at the right of the header.
 * @param {Node}    props.footer       Footer content.
 * @param {string}  props.tone         'attention' gives an amber border (the card that needs action).
 * @param {string}  props.className    Extra class.
 * @param {boolean} props.flush        No body padding (tables).
 * @param {Node}    props.children     Body.
 * @return {JSX.Element} Card.
 */
export default function Card( {
	title,
	headingLevel = 2,
	description,
	actions,
	footer,
	tone,
	className = '',
	flush = false,
	children,
} ) {
	const Heading = `h${ Math.min( 4, Math.max( 2, headingLevel ) ) }`;
	const classes = [
		'seoc-card',
		tone ? `seoc-card--${ tone }` : '',
		flush ? 'seoc-card--flush' : '',
		className,
	]
		.filter( Boolean )
		.join( ' ' );
	return (
		<WPCard className={ classes }>
			{ ( title || actions ) && (
				<CardHeader className="seoc-card__header">
					<div className="seoc-card__heading">
						{ title && (
							<Heading className="seoc-card__title">
								{ title }
							</Heading>
						) }
						{ description && (
							<p className="seoc-card__description">
								{ description }
							</p>
						) }
					</div>
					{ actions && (
						<div className="seoc-card__actions">{ actions }</div>
					) }
				</CardHeader>
			) }
			<CardBody className="seoc-card__body">{ children }</CardBody>
			{ footer && (
				<CardFooter className="seoc-card__footer">
					{ footer }
				</CardFooter>
			) }
		</WPCard>
	);
}
