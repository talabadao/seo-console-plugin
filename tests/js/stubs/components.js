/**
 * Minimal stand-ins for @wordpress/components (provided by WordPress at runtime, not installed locally),
 * so the shared components can be rendered in jsdom. They keep labels and roles so tests query like a user.
 */
const React = require( '@wordpress/element' );

const h = React.createElement;

const Button = React.forwardRef( function Button(
	{
		children,
		href,
		onClick,
		disabled,
		variant,
		isBusy,
		isDestructive,
		size,
		...rest
	},
	ref
) {
	if ( href ) {
		return h( 'a', { href, ref, ...rest }, children );
	}
	return h(
		'button',
		{
			ref,
			type: rest.type || 'button',
			onClick,
			disabled,
			'data-variant': variant,
			...rest,
		},
		children
	);
} );

const div = ( name ) =>
	function Stub( { children, className } ) {
		return h( 'div', { className, 'data-stub': name }, children );
	};

function Notice( { children, status, actions } ) {
	return h(
		'div',
		{ 'data-notice': status },
		children,
		( actions || [] ).map( ( a ) =>
			h( 'button', { key: a.label, onClick: a.onClick }, a.label )
		)
	);
}

function field( type ) {
	return function Field( {
		label,
		value,
		onChange,
		id,
		help,
		disabled,
		...rest
	} ) {
		const inputId = id || `f-${ String( label ).replace( /\W+/g, '-' ) }`;
		const props = {
			id: inputId,
			value: value ?? '',
			disabled,
			onChange: ( e ) => onChange( e.target.value ),
		};
		let control;
		if ( type === 'textarea' ) {
			control = h( 'textarea', props );
		} else if ( type === 'select' ) {
			control = h(
				'select',
				props,
				( rest.options || [] ).map( ( o ) =>
					h( 'option', { key: o.value, value: o.value }, o.label )
				)
			);
		} else {
			control = h( 'input', { ...props, type: rest.type || 'text' } );
		}
		return h(
			'div',
			null,
			h( 'label', { htmlFor: inputId }, label ),
			control,
			help ? h( 'p', null, help ) : null
		);
	};
}

function ToggleControl( { label, checked, onChange, help } ) {
	const id = `t-${ String( label ).replace( /\W+/g, '-' ) }`;
	return h(
		'div',
		null,
		h( 'input', {
			id,
			type: 'checkbox',
			checked: !! checked,
			onChange: ( e ) => onChange( e.target.checked ),
		} ),
		h( 'label', { htmlFor: id }, label ),
		help ? h( 'p', null, help ) : null
	);
}

function TabPanel( { tabs, initialTabName, onSelect, children } ) {
	const [ current, setCurrent ] = React.useState(
		initialTabName || tabs[ 0 ].name
	);
	const tab = tabs.find( ( t ) => t.name === current ) || tabs[ 0 ];
	return h(
		'div',
		null,
		h(
			'div',
			{ role: 'tablist' },
			tabs.map( ( t ) =>
				h(
					'button',
					{
						key: t.name,
						role: 'tab',
						'aria-selected': t.name === current,
						onClick: () => {
							setCurrent( t.name );
							onSelect( t.name );
						},
					},
					t.title
				)
			)
		),
		children( tab )
	);
}

module.exports = {
	Button,
	Card: div( 'card' ),
	CardBody: div( 'card-body' ),
	CardHeader: div( 'card-header' ),
	CardFooter: div( 'card-footer' ),
	Notice,
	Spinner: () => h( 'span', null, '…' ),
	TextControl: field( 'text' ),
	TextareaControl: field( 'textarea' ),
	SelectControl: field( 'select' ),
	ToggleControl,
	TabPanel,
	ExternalLink: ( { href, children } ) => h( 'a', { href }, children ),
};
