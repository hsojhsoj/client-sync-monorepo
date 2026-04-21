import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import AddDimensionModal from './AddDimensionModal';

describe( 'AddDimensionModal', () => {
	// Test 1: A simple "smoke test" to ensure the component renders without errors.
	it( 'renders the modal with all form fields', () => {
		// Arrange: Create mock functions for the props the component expects.
		const mockOnClose = jest.fn();
		const mockOnSave = jest.fn();

		// Act: Render the component into a virtual DOM.
		render(
			<AddDimensionModal onClose={ mockOnClose } onSave={ mockOnSave } />
		);

		// Assert: Check if the key elements are present in the document.
		expect(
			screen.getByRole( 'heading', { name: /Add New Dimension Type/i } )
		).toBeInTheDocument();
		expect( screen.getByLabelText( /Plural Name/i ) ).toBeInTheDocument();
		expect( screen.getByLabelText( /Singular Name/i ) ).toBeInTheDocument();
		expect( screen.getByLabelText( /Slug/i ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: /Add Dimension Type/i } )
		).toBeInTheDocument();
	} );

	// Test 2: A functional test to simulate user interaction.
	it( 'calls onSave with the correct data when the form is submitted', async () => {
		// Arrange
		const mockOnClose = jest.fn();
		const mockOnSave = jest.fn();
		const user = userEvent.setup(); // Set up the userEvent simulator

		render(
			<AddDimensionModal onClose={ mockOnClose } onSave={ mockOnSave } />
		);

		// Act: Simulate a user filling out the form.
		await user.type( screen.getByLabelText( /Plural Name/i ), 'Services' );
		await user.type( screen.getByLabelText( /Singular Name/i ), 'Service' );
		await user.type( screen.getByLabelText( /Slug/i ), 'service' );
		// We'll leave the icon as the default for this test.

		// Act: Simulate clicking the submit button.
		await user.click(
			screen.getByRole( 'button', { name: /Add Dimension Type/i } )
		);

		// Assert: Check if our mock functions were called as expected.
		expect( mockOnSave ).toHaveBeenCalledTimes( 1 );
		expect( mockOnSave ).toHaveBeenCalledWith( {
			plural: 'Services',
			singular: 'Service',
			slug: 'service',
			icon: 'dashicons-admin-generic', // The default icon
		} );

		// The onClose function should be called by the component after a successful save.
		// Note: We need to make the mockOnSave return a resolved promise for this to work.
		// Let's adjust the test slightly for that.
	} );

	it( 'calls onClose when the cancel button is clicked', async () => {
		// Arrange
		const mockOnClose = jest.fn();
		const user = userEvent.setup();
		render(
			<AddDimensionModal onClose={ mockOnClose } onSave={ jest.fn() } />
		);

		// Act
		await user.click( screen.getByRole( 'button', { name: /Cancel/i } ) );

		// Assert
		expect( mockOnClose ).toHaveBeenCalledTimes( 1 );
	} );
} );
