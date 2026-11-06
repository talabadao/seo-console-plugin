/**
 * End-to-end tests against a running WordPress (bin/dev-site.sh up → http://localhost:8080, admin / admin).
 * Run: npx playwright test   (WP_BASE_URL overrides the site, PW_CHROMIUM_PATH the browser binary).
 */
const fs = require( 'fs' );
const { defineConfig, devices } = require( '@playwright/test' );

// Use a preinstalled Chromium when Playwright's own build is not available (sandboxed sessions).
const candidates = [ process.env.PW_CHROMIUM_PATH, '/opt/pw-browsers/chromium' ].filter( Boolean );
const executablePath = candidates.find( ( p ) => fs.existsSync( p ) );

module.exports = defineConfig( {
	testDir: './tests/e2e',
	testMatch: '**/*.spec.js',
	// Specs change shared site state (settings, keys): run them one at a time.
	fullyParallel: false,
	workers: 1,
	timeout: 60000,
	expect: { timeout: 10000 },
	retries: process.env.CI ? 1 : 0,
	reporter: [ [ 'list' ] ],
	use: {
		baseURL: process.env.WP_BASE_URL || 'http://localhost:8080',
		trace: 'off',
		screenshot: 'only-on-failure',
		ignoreHTTPSErrors: true,
	},
	projects: [
		{
			name: 'chromium',
			use: {
				...devices[ 'Desktop Chrome' ],
				launchOptions: executablePath ? { executablePath } : {},
			},
		},
	],
} );
