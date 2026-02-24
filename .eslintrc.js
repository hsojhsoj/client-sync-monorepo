/**
 * ESLint Configuration — Client Sync Monorepo
 *
 * Extends the WordPress scripts defaults with project-specific overrides.
 */
const defaultConfig = require( '@wordpress/scripts/config/.eslintrc.js' );

module.exports = {
	...defaultConfig,
	rules: {
		...defaultConfig.rules,
		// Allow console.warn/error for debugging (but not console.log in production).
		'no-console': [ 'warn', { allow: [ 'warn', 'error' ] } ],
		// Relax JSX pragma requirement — we use React 17+ automatic runtime.
		'react/react-in-jsx-scope': 'off',
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
