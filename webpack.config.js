const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const CopyWebpackPlugin = require( 'copy-webpack-plugin' );
const MiniCssExtractPlugin = require( 'mini-css-extract-plugin' );

// ── Unified output root for all consolidated bundles ──
const DIST_ROOT = path.resolve( __dirname, 'src/assets/dist' );

// Modify plugins: CopyWebpackPlugin exclusions + CSS output path fix.
const modifiedPlugins = defaultConfig.plugins.map( ( plugin ) => {
	// Exclude dist/ from CopyWebpackPlugin to prevent copying built files.
	if ( plugin instanceof CopyWebpackPlugin ) {
		const existingPatterns = plugin.patterns || [];
		const modifiedPatterns = existingPatterns.map( ( pattern ) => {
			if ( typeof pattern === 'object' && pattern.from ) {
				return {
					...pattern,
					globOptions: {
						...( pattern.globOptions || {} ),
						ignore: [
							...( pattern.globOptions?.ignore || [] ),
							'**/dist/**',
						],
					},
				};
			}
			return pattern;
		} );
		return new CopyWebpackPlugin( { patterns: modifiedPatterns } );
	}

	// Override MiniCssExtractPlugin to place CSS inside each entry's subdirectory
	// so PHP can find it at e.g. assets/dist/booking-form/style-index.css
	if ( plugin instanceof MiniCssExtractPlugin ) {
		return new MiniCssExtractPlugin( {
			filename: '[name]/style-index.css',
		} );
	}

	return plugin;
} );

module.exports = {
	...defaultConfig,

	// ── 11 entry points → single compilation ──
	entry: {
		// Shared admin apps
		'graph':              './src/assets/src/index.js',
		'manage-slots':       './src/assets/src/manage-slots/index.js',
		'schedule-editor':    './src/assets/src/schedule-editor/index.js',

		// Frontend shortcode apps
		'booking-form':       './src/assets/src/booking-form/index.js',
		'faceted-booking':    './src/assets/src/faceted-booking/index.js',
		'hybrid-booking':     './src/assets/src/hybrid-booking/index.js',
		'timeline-viewer':    './src/assets/src/timeline-viewer/index.js',
		'booking-wizard':     './src/assets/src/booking-wizard/index.js',

		// Pro module apps
		'pro-forms':          './src/pro/includes/modules/forms/js/src/index.js',
		'pro-outputs':        './src/pro/includes/modules/outputs/js/src/index.js',
		'pro-seat-selection': './src/pro/includes/modules/seat-selection/js/src/index.js',

		// Lightweight utility scripts
		'frontend-qr':        './src/assets/src/frontend-qr/index.js',
		'frontend-venue-map': './src/assets/src/frontend-venue-map/index.js',
		'admin-checkin':      './src/assets/src/admin-checkin/index.js',
	},

	output: {
		...defaultConfig.output,
		path: DIST_ROOT,
		filename: '[name]/index.js',
	},

	// ── Code splitting: extract heavy vendor libraries into shared chunks ──
	optimization: {
		...( defaultConfig.optimization || {} ),
		splitChunks: {
			cacheGroups: {
				// Extract FullCalendar into a shared vendor chunk.
				// Used by: booking-form, manage-slots, schedule-editor, timeline-viewer.
				fullcalendar: {
					test: /[\\/]node_modules[\\/]@fullcalendar[\\/]/,
					name: 'vendor-fullcalendar',
					chunks: 'all',
					priority: 20,
					enforce: true,
				},
				// Extract React Flow (used by graph + timeline) into a shared chunk.
				reactflow: {
					test: /[\\/]node_modules[\\/]@xyflow[\\/]/,
					name: 'vendor-reactflow',
					chunks: 'all',
					priority: 20,
					enforce: true,
				},
			},
		},
	},

	plugins: modifiedPlugins,
};
