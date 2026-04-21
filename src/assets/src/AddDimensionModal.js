/**
 * File: src/assets/src/AddDimensionModal.js -> client-sync/assets/src/AddDimensionModal.js
 * Add Dimension Modal Component
 *
 * This file contains the React component for the "Add New Dimension Type" modal window.
 * It handles the form state, user input, and triggers the `onSave` callback upon
 * successful submission. It also integrates the IconPickerModal.
 * REFACTORED: Prefixes updated to 'clisyc' to meet WordPress.org standards.
 */
import React, { useState } from 'react';
import IconPickerModal from './IconPickerModal';

const AddDimensionModal = ( { onClose, onSave } ) => {
	const [ plural, setPlural ] = useState( '' );
	const [ singular, setSingular ] = useState( '' );
	const [ slug, setSlug ] = useState( '' );
	const [ icon, setIcon ] = useState( 'dashicons-admin-generic' );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ isIconPickerOpen, setIconPickerOpen ] = useState( false );

	const handleSlugChange = ( e ) => {
		setSlug( e.target.value.toLowerCase().replace( /[^a-z0-9_]/g, '' ) );
	};

	const handleSubmit = async ( e ) => {
		e.preventDefault();
		if ( ! plural || ! singular || ! slug ) {
			setError( 'Plural Name, Singular Name, and Slug are required.' );
			return;
		}

		setIsLoading( true );
		setError( '' );

		try {
			await onSave( {
				plural,
				singular,
				slug,
				icon,
			} );
			onClose(); // Close modal on success
		} catch ( err ) {
			setError( err.message || 'An unknown error occurred.' );
		} finally {
			setIsLoading( false );
		}
	};

	const handleIconSelect = ( selectedIcon ) => {
		setIcon( selectedIcon );
		setIconPickerOpen( false );
	};

	return (
		<>
			<IconPickerModal
				isOpen={ isIconPickerOpen }
				onClose={ () => setIconPickerOpen( false ) }
				onSelect={ handleIconSelect }
			/>
			{ /* *** REFACTORED: Updated CSS classes *** */ }
			<div className="clisyc-node-modal-overlay">
				<div className="clisyc-node-modal">
					<form onSubmit={ handleSubmit }>
						<h2>Add New Dimension Type</h2>
						<p>
							Create a new content type to use as a schedulable
							dimension.
						</p>
						{ error && (
							<div className="clisyc-node-modal-error">
								{ error }
							</div>
						) }
						<div className="form-field">
							<label htmlFor="dim_plural_name">Plural Name</label>
							<input
								id="dim_plural_name"
								type="text"
								value={ plural }
								onChange={ ( e ) =>
									setPlural( e.target.value )
								}
								required
							/>
							<p className="description">Example: Rental Cars</p>
						</div>
						<div className="form-field">
							<label htmlFor="dim_singular_name">
								Singular Name
							</label>
							<input
								id="dim_singular_name"
								type="text"
								value={ singular }
								onChange={ ( e ) =>
									setSingular( e.target.value )
								}
								required
							/>
							<p className="description">Example: Rental Car</p>
						</div>
						<div className="form-field">
							<label htmlFor="dim_slug">Slug</label>
							<input
								id="dim_slug"
								type="text"
								value={ slug }
								onChange={ handleSlugChange }
								required
							/>
							<p className="description">
								Unique identifier (a-z, 0-9, _). The `clisyc_`
								prefix will be added.
							</p>
						</div>
						<div className="form-field">
							<label htmlFor="dim_icon">Menu Icon</label>
							<div style={ { display: 'flex', gap: '10px' } }>
								<input
									id="dim_icon"
									type="text"
									value={ icon }
									onChange={ ( e ) =>
										setIcon( e.target.value )
									}
									style={ { flexGrow: 1 } }
								/>
								<button
									type="button"
									className="button"
									onClick={ () => setIconPickerOpen( true ) }
								>
									Choose Icon
								</button>
							</div>
							<p className="description">
								Enter a Dashicon class name or choose one from
								the picker.
							</p>
						</div>
						<div className="clisyc-node-modal-actions">
							<button
								type="button"
								className="button"
								onClick={ onClose }
								disabled={ isLoading }
							>
								Cancel
							</button>
							<button
								type="submit"
								className="button button-primary"
								disabled={ isLoading }
							>
								{ isLoading
									? 'Saving...'
									: 'Add Dimension Type' }
							</button>
						</div>
					</form>
				</div>
			</div>
		</>
	);
};

export default AddDimensionModal;
