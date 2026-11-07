/**
 * One bundle per admin page: every src/admin/<page>/index.js becomes build/<page>.js
 * (+ build/<page>.asset.php with the WordPress script dependencies).
 */
const fs = require( 'fs' );
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

const adminDir = path.resolve( __dirname, 'src/admin' );
const entry = {};
for ( const name of fs.readdirSync( adminDir ) ) {
	const file = path.join( adminDir, name, 'index.js' );
	if ( name !== 'shared' && fs.existsSync( file ) ) {
		entry[ name ] = file;
	}
}

module.exports = {
	...defaultConfig,
	entry,
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
	},
};
