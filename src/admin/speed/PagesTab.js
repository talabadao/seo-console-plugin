/**
 * Pages tested: automatic picks or the user's own list, devices, schedule, real-user data.
 */
import {
	Button,
	RadioControl,
	SelectControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { Card, ErrorNotice, Loading } from '../shared';
import { saveUrls } from './api';

export default function PagesTab( { urls, addNotice, onSaved } ) {
	const [ draft, setDraft ] = useState( null );
	const [ errors, setErrors ] = useState( [] );
	const [ saving, setSaving ] = useState( false );

	useEffect( () => {
		if ( urls.data ) {
			setDraft( {
				url_mode: urls.data.url_mode,
				custom: urls.data.custom_urls.join( '\n' ),
				strategies: urls.data.strategies,
				psi_schedule: urls.data.psi_schedule,
				crux_enabled: urls.data.crux_enabled,
			} );
		}
	}, [ urls.data ] );

	if ( urls.loading && ! urls.data ) {
		return <Loading />;
	}
	if ( urls.error ) {
		return <ErrorNotice error={ urls.error } onRetry={ urls.reload } />;
	}
	if ( ! draft ) {
		return null;
	}
	const data = urls.data;
	const set = ( patch ) => setDraft( ( d ) => ( { ...d, ...patch } ) );

	const save = () => {
		setSaving( true );
		setErrors( [] );
		saveUrls( {
			url_mode: draft.url_mode,
			custom_urls: draft.custom
				.split( /\r?\n/ )
				.map( ( s ) => s.trim() )
				.filter( Boolean ),
			strategies: draft.strategies,
			psi_schedule: draft.psi_schedule,
			crux_enabled: draft.crux_enabled,
		} ).then(
			( res ) => {
				setSaving( false );
				urls.setData( res );
				addNotice(
					'success',
					__(
						'Saved. The next speed test uses these settings.',
						'seo-console'
					)
				);
				onSaved();
			},
			( error ) => {
				setSaving( false );
				setErrors(
					error.data && Array.isArray( error.data.errors )
						? error.data.errors
						: []
				);
				addNotice( 'error', error.message );
			}
		);
	};

	return (
		<Card
			title={ __( 'Pages to test', 'seo-console' ) }
			description={ __(
				'Testing a few typical pages shows how the whole site performs: pages built from the same template usually behave the same.',
				'seo-console'
			) }
			footer={
				<Button
					variant="primary"
					isBusy={ saving }
					disabled={ saving }
					onClick={ save }
				>
					{ __( 'Save', 'seo-console' ) }
				</Button>
			}
		>
			<div className="seoc-form">
				<RadioControl
					label={ __( 'Which pages', 'seo-console' ) }
					selected={ draft.url_mode }
					options={ [
						{
							value: 'auto',
							label: __(
								'Choose automatically (recommended)',
								'seo-console'
							),
						},
						{
							value: 'custom',
							label: __( 'Use my own list', 'seo-console' ),
						},
					] }
					onChange={ ( v ) => set( { url_mode: v } ) }
				/>
				{ draft.url_mode === 'auto' ? (
					<ul className="seoc-plain-list">
						{ data.auto.map( ( p ) => (
							<li key={ p.url }>
								<strong>{ p.label }</strong>{ ' ' }
								<a
									href={ p.url }
									target="_blank"
									rel="noreferrer"
								>
									{ p.url }
								</a>
							</li>
						) ) }
					</ul>
				) : (
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Pages (one per line)', 'seo-console' ) }
						help={ __(
							'Full addresses or paths such as /contact/. Only pages of this site, at most 10.',
							'seo-console'
						) }
						value={ draft.custom }
						rows={ 6 }
						onChange={ ( v ) => set( { custom: v } ) }
					/>
				) }
				{ errors.length > 0 && (
					<ul className="seoc-plain-list">
						{ errors.map( ( e ) => (
							<li key={ e.url }>
								<code>{ e.url }</code>: { e.message }
							</li>
						) ) }
					</ul>
				) }
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Devices', 'seo-console' ) }
					value={ draft.strategies }
					options={ [
						{
							value: 'both',
							label: __( 'Mobile and desktop', 'seo-console' ),
						},
						{
							value: 'mobile',
							label: __( 'Mobile only', 'seo-console' ),
						},
						{
							value: 'desktop',
							label: __( 'Desktop only', 'seo-console' ),
						},
					] }
					onChange={ ( v ) => set( { strategies: v } ) }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Automatic tests', 'seo-console' ) }
					value={ draft.psi_schedule }
					options={ [
						{
							value: 'daily',
							label: __( 'Every night', 'seo-console' ),
						},
						{
							value: 'weekly',
							label: __( 'Once a week', 'seo-console' ),
						},
						{
							value: 'off',
							label: __( 'Off (manual only)', 'seo-console' ),
						},
					] }
					onChange={ ( v ) => set( { psi_schedule: v } ) }
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Collect real-user data from the Chrome UX Report',
						'seo-console'
					) }
					help={
						data.has_key
							? __( 'Fetched every night.', 'seo-console' )
							: __(
									'Needs a Google API key (Settings).',
									'seo-console'
							  )
					}
					checked={ draft.crux_enabled }
					onChange={ ( v ) => set( { crux_enabled: v } ) }
				/>
			</div>
		</Card>
	);
}
