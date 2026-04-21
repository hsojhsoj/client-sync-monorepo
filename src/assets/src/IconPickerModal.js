/**
 * File: src/assets/src/IconPickerModal.js -> client-sync/assets/src/IconPickerModal.js
 * Icon Picker Modal Component
 *
 * A reusable React component that displays a searchable grid of all available
 * WordPress Dashicons. It is used within other modals to provide a user-friendly
 * way to select an icon.
 * REFACTORED: Prefixes updated to 'clisyc' to meet WordPress.org standards.
 */
import React, { useState, useMemo } from 'react';

// A static list of dashicons is more reliable than trying to parse CSS.
const ALL_DASHICONS = [
	'dashicons-menu',
	'dashicons-menu-alt',
	'dashicons-menu-alt2',
	'dashicons-menu-alt3',
	'dashicons-admin-site',
	'dashicons-admin-site-alt',
	'dashicons-admin-site-alt2',
	'dashicons-admin-site-alt3',
	'dashicons-dashboard',
	'dashicons-admin-post',
	'dashicons-admin-media',
	'dashicons-admin-links',
	'dashicons-admin-page',
	'dashicons-admin-comments',
	'dashicons-admin-appearance',
	'dashicons-admin-plugins',
	'dashicons-admin-users',
	'dashicons-admin-tools',
	'dashicons-admin-settings',
	'dashicons-admin-network',
	'dashicons-admin-home',
	'dashicons-admin-generic',
	'dashicons-admin-collapse',
	'dashicons-filter',
	'dashicons-admin-customizer',
	'dashicons-admin-multisite',
	'dashicons-welcome-write-blog',
	'dashicons-welcome-add-page',
	'dashicons-welcome-view-site',
	'dashicons-welcome-widgets-menus',
	'dashicons-welcome-comments',
	'dashicons-welcome-learn-more',
	'dashicons-format-asides',
	'dashicons-format-audio',
	'dashicons-format-chat',
	'dashicons-format-gallery',
	'dashicons-format-image',
	'dashicons-format-links',
	'dashicons-format-quote',
	'dashicons-format-status',
	'dashicons-format-video',
	'dashicons-format-standard',
	'dashicons-image-crop',
	'dashicons-image-rotate',
	'dashicons-image-rotate-left',
	'dashicons-image-rotate-right',
	'dashicons-image-flip-vertical',
	'dashicons-image-flip-horizontal',
	'dashicons-image-filter',
	'dashicons-undo',
	'dashicons-redo',
	'dashicons-editor-bold',
	'dashicons-editor-italic',
	'dashicons-editor-ul',
	'dashicons-editor-ol',
	'dashicons-editor-quote',
	'dashicons-editor-alignleft',
	'dashicons-editor-aligncenter',
	'dashicons-editor-alignright',
	'dashicons-editor-insertmore',
	'dashicons-editor-spellcheck',
	'dashicons-editor-expand',
	'dashicons-editor-contract',
	'dashicons-editor-kitchensink',
	'dashicons-editor-underline',
	'dashicons-editor-justify',
	'dashicons-editor-textcolor',
	'dashicons-editor-paste-word',
	'dashicons-editor-paste-text',
	'dashicons-editor-removeformatting',
	'dashicons-editor-video',
	'dashicons-editor-customchar',
	'dashicons-editor-outdent',
	'dashicons-editor-indent',
	'dashicons-editor-help',
	'dashicons-editor-strikethrough',
	'dashicons-editor-unlink',
	'dashicons-editor-rtl',
	'dashicons-editor-ltr',
	'dashicons-editor-alignfull',
	'dashicons-editor-table',
	'dashicons-align-left',
	'dashicons-align-right',
	'dashicons-align-center',
	'dashicons-align-none',
	'dashicons-lock',
	'dashicons-unlock',
	'dashicons-calendar',
	'dashicons-calendar-alt',
	'dashicons-visibility',
	'dashicons-hidden',
	'dashicons-post-status',
	'dashicons-edit',
	'dashicons-trash',
	'dashicons-sticky',
	'dashicons-external',
	'dashicons-arrow-up',
	'dashicons-arrow-down',
	'dashicons-arrow-right',
	'dashicons-arrow-left',
	'dashicons-arrow-up-alt',
	'dashicons-arrow-down-alt',
	'dashicons-arrow-right-alt',
	'dashicons-arrow-left-alt',
	'dashicons-arrow-up-alt2',
	'dashicons-arrow-down-alt2',
	'dashicons-arrow-right-alt2',
	'dashicons-arrow-left-alt2',
	'dashicons-sort',
	'dashicons-leftright',
	'dashicons-randomize',
	'dashicons-list-view',
	'dashicons-exerpt-view',
	'dashicons-grid-view',
	'dashicons-cloud-upload',
	'dashicons-cloud-download',
	'dashicons-cloud-saved',
	'dashicons-cloud-off',
	'dashicons-move',
	'dashicons-controls-repeat',
	'dashicons-controls-skipback',
	'dashicons-controls-skipforward',
	'dashicons-controls-play',
	'dashicons-controls-pause',
	'dashicons-controls-volumeon',
	'dashicons-controls-volumeoff',
	'dashicons-controls-back',
	'dashicons-controls-forward',
	'dashicons-plus',
	'dashicons-minus',
	'dashicons-dismiss',
	'dashicons-marker',
	'dashicons-star-filled',
	'dashicons-star-half',
	'dashicons-star-empty',
	'dashicons-flag',
	'dashicons-info',
	'dashicons-info-outline',
	'dashicons-warning',
	'dashicons-share',
	'dashicons-share-alt',
	'dashicons-share-alt2',
	'dashicons-twitter',
	'dashicons-rss',
	'dashicons-email',
	'dashicons-email-alt',
	'dashicons-email-alt2',
	'dashicons-facebook',
	'dashicons-facebook-alt',
	'dashicons-google',
	'dashicons-googleplus',
	'dashicons-networking',
	'dashicons-hammer',
	'dashicons-art',
	'dashicons-migrate',
	'dashicons-performance',
	'dashicons-universal-access',
	'dashicons-universal-access-alt',
	'dashicons-tickets',
	'dashicons-nametag',
	'dashicons-clipboard',
	'dashicons-heart',
	'dashicons-megaphone',
	'dashicons-schedule',
	'dashicons-wordpress',
	'dashicons-wordpress-alt',
	'dashicons-pressthis',
	'dashicons-update',
	'dashicons-update-alt',
	'dashicons-screenoptions',
	'dashicons-layout',
	'dashicons-cart',
	'dashicons-feedback',
	'dashicons-cloud',
	'dashicons-translation',
	'dashicons-tag',
	'dashicons-category',
	'dashicons-archive',
	'dashicons-tagcloud',
	'dashicons-text',
	'dashicons-yes',
	'dashicons-yes-alt',
	'dashicons-no',
	'dashicons-no-alt',
	'dashicons-plus-alt',
	'dashicons-minus-alt',
	'dashicons-exerpt-view',
	'dashicons-excerpt-view',
	'dashicons-thumbs-up',
	'dashicons-thumbs-down',
	'dashicons-chart-pie',
	'dashicons-chart-bar',
	'dashicons-chart-line',
	'dashicons-chart-area',
	'dashicons-groups',
	'dashicons-businessman',
	'dashicons-id',
	'dashicons-id-alt',
	'dashicons-products',
	'dashicons-awards',
	'dashicons-forms',
	'dashicons-testimonial',
	'dashicons-portfolio',
	'dashicons-book',
	'dashicons-book-alt',
	'dashicons-download',
	'dashicons-upload',
	'dashicons-backup',
	'dashicons-clock',
	'dashicons-lightbulb',
	'dashicons-microphone',
	'dashicons-desktop',
	'dashicons-laptop',
	'dashicons-tablet',
	'dashicons-smartphone',
	'dashicons-phone',
	'dashicons-index-card',
	'dashicons-carrot',
	'dashicons-building',
	'dashicons-store',
	'dashicons-album',
	'dashicons-palmtree',
	'dashicons-tickets-alt',
	'dashicons-money',
	'dashicons-money-alt',
	'dashicons-smiley',
	'dashicons-thumbs-up',
	'dashicons-thumbs-down',
	'dashicons-layout',
	'dashicons-paperclip',
	'dashicons-slides',
	'dashicons-analytics',
	'dashicons-chart-pie',
	'dashicons-chart-bar',
	'dashicons-chart-line',
	'dashicons-chart-area',
	'dashicons-video-alt',
	'dashicons-video-alt2',
	'dashicons-video-alt3',
];

const IconPickerModal = ( { isOpen, onClose, onSelect } ) => {
	const [ filter, setFilter ] = useState( '' );

	const filteredIcons = useMemo( () => {
		if ( ! filter ) {
			return ALL_DASHICONS;
		}
		return ALL_DASHICONS.filter( ( icon ) =>
			icon.includes( filter.toLowerCase() )
		);
	}, [ filter ] );

	if ( ! isOpen ) {
		return null;
	}

	// *** REFACTORED: Updated all clisyc- CSS classes ***
	return (
		<div className="clisyc-node-modal-overlay clisyc-icon-picker-modal-overlay">
			<div className="clisyc-node-modal" style={ { maxWidth: '800px' } }>
				<h2>Choose an Icon</h2>
				<div className="clisyc-icon-picker-controls">
					<input
						type="search"
						placeholder="Search icons..."
						value={ filter }
						onChange={ ( e ) => setFilter( e.target.value ) }
						className="clisyc-icon-picker-search"
					/>
				</div>
				<div className="clisyc-icon-picker-grid">
					{ filteredIcons.length > 0 ? (
						filteredIcons.map( ( iconClass ) => (
							<div
								key={ iconClass }
								className="clisyc-icon-picker-item"
								tabIndex="0"
								onClick={ () => onSelect( iconClass ) }
								onKeyDown={ ( e ) =>
									e.key === 'Enter' && onSelect( iconClass )
								}
							>
								<span
									className={ `dashicons ${ iconClass }` }
								></span>
								<span className="clisyc-icon-picker-label">
									{ iconClass.replace( 'dashicons-', '' ) }
								</span>
							</div>
						) )
					) : (
						<p>No icons found.</p>
					) }
				</div>
				<div className="clisyc-node-modal-actions">
					<button
						type="button"
						className="button"
						onClick={ onClose }
					>
						Cancel
					</button>
				</div>
			</div>
		</div>
	);
};

export default IconPickerModal;
