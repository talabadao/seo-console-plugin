/* @wordpress/api-fetch is provided by WordPress at runtime (not installed locally). */
jest.mock( '@wordpress/api-fetch', () => jest.fn(), { virtual: true } );

import {
	normaliseSuggestion,
	stripDisplaySuffix,
} from '../../src/admin/shared/api';

describe( 'suggestion display suffix', () => {
	it( 'hides the template suffix from shown and edited values', () => {
		const item = normaliseSuggestion( {
			id: 3,
			field: 'title',
			current_value: 'Old title %sep% %sitename%',
			proposed_value: 'Cách chọn rượu vang ngon %sep% %sitename%',
			meta: {
				display_suffix: ' %sep% %sitename%',
				brand_suffix_resolved: ' - Wine Shop',
				warnings: [ { code: 'too_short', message: 'Short' }, 'bad' ],
			},
		} );
		expect( item.proposedValue ).toBe( 'Cách chọn rượu vang ngon' );
		expect( item.currentValue ).toBe( 'Old title' );
		expect( item.displaySuffix ).toBe( ' %sep% %sitename%' );
		expect( item.displaySuffixResolved ).toBe( ' - Wine Shop' );
		expect( item.warnings ).toEqual( [
			{ code: 'too_short', message: 'Short' },
		] );
	} );

	it( 'leaves values without the suffix or without meta alone', () => {
		expect(
			stripDisplaySuffix( 'Custom title', ' %sep% %sitename%' )
		).toBe( 'Custom title' );
		expect( stripDisplaySuffix( { a: 1 }, ' x' ) ).toEqual( { a: 1 } );
		const item = normaliseSuggestion( { proposed_value: 'A %sep% B' } );
		expect( item.proposedValue ).toBe( 'A %sep% B' );
		expect( item.meta ).toBeNull();
		expect( item.displaySuffix ).toBe( '' );
	} );
} );
