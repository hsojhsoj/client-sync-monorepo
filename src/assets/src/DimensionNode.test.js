import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import DimensionNode from './DimensionNode';

// We don't need to test the inner workings of the Handle, so we mock it.
// This makes our test more focused and less brittle.
jest.mock('./HandleWithTooltip', () => () => <div data-testid="mock-handle" />);

describe('DimensionNode', () => {
  // Define a set of default props for our mock data to keep tests clean.
  const defaultProps = {
    isConnectable: true,
    data: {
      label: 'Services',
      slug: 'clisyc_service',
      icon: 'dashicons-clipboard',
      childCount: 5,
      isPrimary: false,
      isExpanded: false,
      onSetPrimary: jest.fn(),
      onExpandToggle: jest.fn(),
      onOrganizeChildren: jest.fn(),
    },
  };

  // This runs before each test, ensuring mocks are cleared and tests are isolated.
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it('renders the basic node information including label, child count, and slug', () => {
    render(<DimensionNode {...defaultProps} />);

    expect(screen.getByText('Services (5)')).toBeInTheDocument();
    expect(screen.getByText('clisyc_service')).toBeInTheDocument();
    expect(document.querySelector('.dashicons-clipboard')).toBeInTheDocument();
  });

  it('displays the primary star icon only when isPrimary is true', () => {
    // Render the component WITHOUT the primary prop first
    const { rerender } = render(<DimensionNode {...defaultProps} />);
    expect(screen.queryByTitle('Primary Dimension')).not.toBeInTheDocument();

    // Now, re-render the SAME component with the isPrimary prop set to true
    const primaryProps = { ...defaultProps, data: { ...defaultProps.data, isPrimary: true } };
    rerender(<DimensionNode {...primaryProps} />);
    expect(screen.getByTitle('Primary Dimension')).toBeInTheDocument();
  });

  it('calls onSetPrimary with its slug on double click when it is NOT primary', async () => {
    const user = userEvent.setup();
    render(<DimensionNode {...defaultProps} />);

    await user.dblClick(screen.getByText('Services (5)'));

    expect(defaultProps.data.onSetPrimary).toHaveBeenCalledTimes(1);
    expect(defaultProps.data.onSetPrimary).toHaveBeenCalledWith('clisyc_service');
  });

  it('does NOT call onSetPrimary on double click when it IS already primary', async () => {
    const user = userEvent.setup();
    const primaryProps = { ...defaultProps, data: { ...defaultProps.data, isPrimary: true } };
    render(<DimensionNode {...primaryProps} />);

    await user.dblClick(screen.getByText('Services (5)'));

    expect(primaryProps.data.onSetPrimary).not.toHaveBeenCalled();
  });

  it('calls onExpandToggle when the visibility icon is clicked', async () => {
    const user = userEvent.setup();
    render(<DimensionNode {...defaultProps} />);

    await user.click(screen.getByTitle('Show child items'));

    expect(defaultProps.data.onExpandToggle).toHaveBeenCalledTimes(1);
    // It should be called with the slug and the NEW desired state (true)
    expect(defaultProps.data.onExpandToggle).toHaveBeenCalledWith('clisyc_service', true);
  });
  
  it('shows the organize button and calls onOrganizeChildren only when expanded', async () => {
    const user = userEvent.setup();
    
    // Render the un-expanded version first
    const { rerender } = render(<DimensionNode {...defaultProps} />);
    expect(screen.queryByTitle('Organize child nodes')).not.toBeInTheDocument();

    // Re-render as expanded
    const expandedProps = { ...defaultProps, data: { ...defaultProps.data, isExpanded: true } };
    rerender(<DimensionNode {...expandedProps} />);
    
    // The button should now be present
    const organizeButton = screen.getByTitle('Organize child nodes');
    expect(organizeButton).toBeInTheDocument();
    
    // Click the button and verify the mock was called
    await user.click(organizeButton);
    expect(expandedProps.data.onOrganizeChildren).toHaveBeenCalledTimes(1);
    expect(expandedProps.data.onOrganizeChildren).toHaveBeenCalledWith('clisyc_service');
  });
});