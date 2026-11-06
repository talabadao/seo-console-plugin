/**
 * Business info tab: the site-wide Organization / LocalBusiness from module settings, with a live
 * preview (GET schema/site with the unsaved values) and the module options.
 */
import {
	Button,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
	Card,
	ErrorNotice,
	Loading,
	StatusBadge,
	apiGet,
	apiPost,
	useAsync,
} from '../shared';
import { getSite } from './api';

const LIST_KEYS = [ 'opening_hours', 'same_as' ];

const TEXT_FIELDS = [
	[ 'business_name', __( 'Name', 'seo-console' ) ],
	[ 'business_url', __( 'Website address', 'seo-console' ) ],
	[ 'business_logo', __( 'Logo URL', 'seo-console' ) ],
	[
		'business_image',
		__( 'Photo URL (for example your shop front)', 'seo-console' ),
	],
	[ 'business_phone', __( 'Phone', 'seo-console' ) ],
	[ 'business_email', __( 'Email', 'seo-console' ) ],
];

const ADDRESS_FIELDS = [
	[ 'address_street', __( 'Street address', 'seo-console' ) ],
	[ 'address_locality', __( 'City / district', 'seo-console' ) ],
	[ 'address_region', __( 'Province / region', 'seo-console' ) ],
	[ 'address_postal_code', __( 'Postal code', 'seo-console' ) ],
	[ 'address_country', __( 'Country code (for example VN)', 'seo-console' ) ],
];

const OPTION_FIELDS = [
	[
		'output_enabled',
		__( 'Add approved structured data to the site', 'seo-console' ),
		__(
			'Turn off to stop all SEO Console structured data output without deleting anything.',
			'seo-console'
		),
	],
	[ 'detect_faq', __( 'Find FAQs in content', 'seo-console' ) ],
	[ 'detect_howto', __( 'Find step-by-step instructions', 'seo-console' ) ],
	[ 'detect_video', __( 'Find YouTube and Vimeo videos', 'seo-console' ) ],
	[ 'detect_product', __( 'Use WooCommerce product data', 'seo-console' ) ],
	[
		'basic_without_seo_plugin',
		__(
			'Add article and breadcrumbs when no SEO plugin does',
			'seo-console'
		),
	],
	[ 'ai_suggestions', __( 'Allow AI suggestions', 'seo-console' ) ],
];

/**
 * Form values as strings / booleans (lists become one item per line).
 *
 * @param {Object} raw modules.schema from GET /settings.
 * @return {Object} Values.
 */
function toForm( raw ) {
	const out = { ...raw };
	LIST_KEYS.forEach( ( key ) => {
		out[ key ] = Array.isArray( raw[ key ] )
			? raw[ key ].join( '\n' )
			: raw[ key ] || '';
	} );
	return out;
}

function businessValues( values, keys ) {
	const out = {};
	keys.forEach( ( key ) => {
		out[ key ] = values[ key ] === undefined ? '' : values[ key ];
	} );
	return out;
}

function Preview( { site, loading } ) {
	if ( ! site ) {
		return loading ? (
			<Loading>{ __( 'Checking…', 'seo-console' ) }</Loading>
		) : null;
	}
	return (
		<>
			<p>
				<StatusBadge
					status={ site.will_output ? 'good' : 'unknown' }
					label={
						site.will_output
							? __(
									'Will be added to your home page',
									'seo-console'
							  )
							: __( 'Not added', 'seo-console' )
					}
				/>{ ' ' }
				{ site.reason }
			</p>
			{ site.errors.length > 0 && (
				<ul className="seoc-schema-list">
					{ site.errors.map( ( e ) => (
						<li key={ e }>{ e }</li>
					) ) }
				</ul>
			) }
			{ site.warnings.length > 0 && (
				<details>
					<summary>
						{ __( 'Optional improvements', 'seo-console' ) }
					</summary>
					<ul className="seoc-schema-list">
						{ site.warnings.map( ( w ) => (
							<li key={ w }>{ w }</li>
						) ) }
					</ul>
				</details>
			) }
			{ site.json && (
				<details>
					<summary>
						{ __( 'Show the JSON-LD', 'seo-console' ) }
					</summary>
					<pre className="seoc-schema-json">{ site.json }</pre>
				</details>
			) }
			{ site.note && <p className="seoc-muted">{ site.note }</p> }
		</>
	);
}

export default function BusinessTab( { addNotice } ) {
	const settings = useAsync(
		( signal ) => apiGet( 'settings', {}, signal ),
		[]
	);
	const [ values, setValues ] = useState( null );
	const [ errors, setErrors ] = useState( {} );
	const [ saving, setSaving ] = useState( false );
	const [ site, setSite ] = useState( null );
	const [ checking, setChecking ] = useState( false );
	const timer = useRef( null );

	const schema =
		settings.data &&
		settings.data.schema &&
		settings.data.schema.modules &&
		settings.data.schema.modules.schema
			? settings.data.schema.modules.schema.settings
			: {};
	const businessKeys = Object.keys( schema ).filter(
		( key ) =>
			key.startsWith( 'business_' ) ||
			key.startsWith( 'address_' ) ||
			key.startsWith( 'geo_' ) ||
			[ 'opening_hours', 'same_as', 'price_range' ].includes( key )
	);

	useEffect( () => {
		if ( settings.data && settings.data.modules && ! values ) {
			setValues( toForm( settings.data.modules.schema || {} ) );
		}
	}, [ settings.data, values ] );

	// Live preview: re-check the business node shortly after each edit (nothing is saved).
	const valuesKey = values
		? JSON.stringify( businessValues( values, businessKeys ) )
		: '';
	useEffect( () => {
		if ( ! values ) {
			return undefined;
		}
		const controller =
			typeof AbortController !== 'undefined'
				? new AbortController()
				: null;
		clearTimeout( timer.current );
		timer.current = setTimeout( () => {
			setChecking( true );
			getSite(
				businessValues( values, businessKeys ),
				controller ? controller.signal : undefined
			)
				.then( ( result ) => {
					setSite( result );
					setErrors( result.field_errors || {} );
				} )
				.catch( () => {} )
				.finally( () => setChecking( false ) );
		}, 500 );
		return () => {
			clearTimeout( timer.current );
			if ( controller ) {
				controller.abort();
			}
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ valuesKey ] );

	if ( settings.error ) {
		return (
			<ErrorNotice
				error={ settings.error }
				onRetry={ settings.reload }
				title={ __( 'Could not load the settings.', 'seo-console' ) }
			/>
		);
	}
	if ( ! values ) {
		return <Loading>{ __( 'Loading settings…', 'seo-console' ) }</Loading>;
	}

	const set = ( key ) => ( value ) =>
		setValues( ( v ) => ( { ...v, [ key ]: value } ) );
	const type = values.business_type || 'none';
	const isLocal = type !== 'none' && type !== 'Organization';
	const typeOptions = (
		( schema.business_type && schema.business_type.enum ) || [ 'none' ]
	).map( ( value ) => {
		if ( value === 'none' ) {
			return {
				value,
				label: __( 'Not set (add nothing)', 'seo-console' ),
			};
		}
		if ( value === 'Organization' ) {
			return {
				value,
				label: __( 'Organization or online business', 'seo-console' ),
			};
		}
		if ( value === 'LocalBusiness' ) {
			return {
				value,
				label: __( 'Local business (general)', 'seo-console' ),
			};
		}
		return { value, label: value.replace( /([a-z])([A-Z])/g, '$1 $2' ) };
	} );

	const save = async () => {
		setSaving( true );
		setErrors( {} );
		const body = {};
		Object.keys( schema ).forEach( ( key ) => {
			if ( values[ key ] !== undefined ) {
				body[ key ] = values[ key ];
			}
		} );
		try {
			const raw = await apiPost( 'settings', {
				modules: { schema: body },
			} );
			setValues( toForm( raw.modules.schema || {} ) );
			addNotice( 'success', __( 'Business info saved.', 'seo-console' ) );
		} catch ( e ) {
			const fieldErrors = {};
			const list = ( e.data && e.data.errors ) || {};
			Object.keys( list ).forEach( ( key ) => {
				fieldErrors[ key.replace( /^schema\./, '' ) ] = list[ key ];
			} );
			setErrors( fieldErrors );
			addNotice( 'error', e.message );
		} finally {
			setSaving( false );
		}
	};

	const text = ( key, label, help ) => (
		<TextControl
			key={ key }
			label={ label }
			value={ values[ key ] || '' }
			onChange={ set( key ) }
			help={ errors[ key ] || help }
			className={ errors[ key ] ? 'has-error' : undefined }
			__nextHasNoMarginBottom
		/>
	);

	return (
		<>
			<Card
				title={ __( 'Your business', 'seo-console' ) }
				description={ __(
					'Shown to search engines on your home page. Use the same name, address and phone as on your Google Business Profile.',
					'seo-console'
				) }
			>
				<SelectControl
					label={ __( 'Business type', 'seo-console' ) }
					value={ type }
					options={ typeOptions }
					onChange={ set( 'business_type' ) }
					__nextHasNoMarginBottom
				/>
				{ type !== 'none' && (
					<>
						<div className="seoc-schema-form">
							{ TEXT_FIELDS.map( ( [ key, label ] ) =>
								text( key, label )
							) }
						</div>
						<TextareaControl
							label={ __( 'Short description', 'seo-console' ) }
							value={ values.business_description || '' }
							onChange={ set( 'business_description' ) }
							rows={ 3 }
							help={ errors.business_description }
							__nextHasNoMarginBottom
						/>
						<h3>{ __( 'Address', 'seo-console' ) }</h3>
						<div className="seoc-schema-form">
							{ ADDRESS_FIELDS.map( ( [ key, label ] ) =>
								text( key, label )
							) }
						</div>
						{ isLocal && (
							<>
								<h3>
									{ __(
										'Location and hours',
										'seo-console'
									) }
								</h3>
								<div className="seoc-schema-form">
									{ text(
										'geo_latitude',
										__( 'Latitude', 'seo-console' ),
										__(
											'From Google Maps, for example 21.0285',
											'seo-console'
										)
									) }
									{ text(
										'geo_longitude',
										__( 'Longitude', 'seo-console' ),
										__(
											'For example 105.8542',
											'seo-console'
										)
									) }
									{ text(
										'price_range',
										__( 'Price range', 'seo-console' ),
										__(
											'For example $$ or 100.000–300.000 ₫',
											'seo-console'
										)
									) }
								</div>
								<TextareaControl
									label={ __(
										'Opening hours',
										'seo-console'
									) }
									help={
										errors.opening_hours ||
										// Hyphens are the input format users must type, not a prose range.
										// eslint-disable-next-line @wordpress/i18n-hyphenated-range
										__(
											'One line per group of days, for example "Mo-Fr 08:00-17:30" and "Sa 09:00-12:00". Days: Mo Tu We Th Fr Sa Su.',
											'seo-console'
										)
									}
									value={ values.opening_hours || '' }
									onChange={ set( 'opening_hours' ) }
									rows={ 4 }
									__nextHasNoMarginBottom
								/>
							</>
						) }
						<TextareaControl
							label={ __( 'Social profiles', 'seo-console' ) }
							help={
								errors.same_as ||
								__(
									'One link per line: Facebook page, YouTube channel, LinkedIn, Zalo OA…',
									'seo-console'
								)
							}
							value={ values.same_as || '' }
							onChange={ set( 'same_as' ) }
							rows={ 3 }
							__nextHasNoMarginBottom
						/>
					</>
				) }
			</Card>

			<Card title={ __( 'Preview', 'seo-console' ) }>
				<Preview site={ site } loading={ checking } />
			</Card>

			<Card
				title={ __( 'Options', 'seo-console' ) }
				footer={
					<Button
						variant="primary"
						onClick={ save }
						isBusy={ saving }
						disabled={ saving }
					>
						{ __( 'Save', 'seo-console' ) }
					</Button>
				}
			>
				{ OPTION_FIELDS.map( ( [ key, label, help ] ) => (
					<ToggleControl
						key={ key }
						label={ label }
						help={ errors[ key ] || help }
						checked={ !! values[ key ] }
						onChange={ set( key ) }
						__nextHasNoMarginBottom
					/>
				) ) }
			</Card>
		</>
	);
}
