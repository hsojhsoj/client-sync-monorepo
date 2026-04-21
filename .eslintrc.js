/**
 * ESLint Configuration — Client Sync Monorepo
 *
 * Extends the WordPress scripts defaults with project-specific overrides.
 */
const defaultConfig = require( '@wordpress/scripts/config/.eslintrc.js' );

module.exports = {
	...defaultConfig,
	env: {
		...( defaultConfig.env || {} ),
		// Standard browser globals (localStorage, sessionStorage, alert,
		// navigator, DOMParser, CSS, ResizeObserver, requestAnimationFrame, …).
		// wp-scripts' recommended config doesn't enable this by default.
		browser: true,
	},
	rules: {
		...defaultConfig.rules,
		// Allow console.warn/error for debugging (but not console.log in production).
		'no-console': [ 'warn', { allow: [ 'warn', 'error' ] } ],
		// Relax JSX pragma requirement — we use React 17+ automatic runtime.
		'react/react-in-jsx-scope': 'off',
		// This codebase uses JSDoc for documentation only (no TypeScript, no
		// JSDoc-driven type-checking in the IDE). Enforcing @param types
		// would add noise (`{*}`) to dozens of plain-description docstrings
		// without any real type-safety benefit. Turn it off.
		'jsdoc/require-param-type': 'off',
		// WordPress-style PHPDoc tags (@subpackage, @since, @see) appear
		// throughout the codebase's file-header docblocks by convention.
		// Add `subpackage` to the definedTags allowlist; the defaults
		// already cover @since, @see, @package, etc.
		'jsdoc/check-tag-names': [
			'error',
			{ definedTags: [ 'subpackage' ] },
		],
		// WordPress REST API responses + `window.clisyc*` config globals
		// come from PHP, which uses snake_case by convention (e.g.
		// `wc_integration_enabled`, `get_nonce`, `buffer_days`). Allow
		// snake_case in destructuring and on property access while still
		// requiring camelCase for locally-declared identifiers.
		camelcase: [
			'error',
			{ properties: 'never', ignoreDestructuring: true },
		],
	},
	overrides: [
		...( defaultConfig.overrides || [] ),
		{
			// Relax rules for test files.
			files: [ '**/*.test.js', '**/*.spec.js', '**/tests/**/*.js' ],
			env: {
				jest: true,
			},
			rules: {
				'no-console': 'off',
				'testing-library/no-node-access': 'off',
			},
		},
	],
};
