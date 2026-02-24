/**
 * Webpack config for the Gutenberg block.
 *
 * The block uses a separate config because it has a different output contract:
 * - Output goes to build/blocks/booking-form/ (not src/assets/dist/)
 * - block.json must be co-located with the compiled JS
 * - WordPress's register_block_type() reads from this directory
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,

	entry: {
		index: './src/blocks/booking-form/index.js',
	},

	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build/blocks/booking-form' ),
	},
};
