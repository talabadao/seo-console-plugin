/**
 * Dashboard page (SCAFFOLD — the core agent replaces it).
 */
import { createRoot } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

function App() {
	return <h1>{ __( 'SEO Console', 'seo-console' ) }</h1>;
}

const root = document.getElementById( 'seoc-root' );
if ( root ) {
	createRoot( root ).render( <App /> );
}
