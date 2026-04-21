/**
 * File: /src/assets/src/hybrid-booking/HybridFilterPanel.js
 * The component that renders the dynamic, multi-select dimension filters using checkboxes.
 * -- CORRECTED TO USE PROPS INSTEAD OF GLOBAL VARIABLE --
 * -- UPDATED WITH COLORED CHECKBOXES, SLOT COUNTS, AND RESET BUTTON --
 *
 * @package
 * @subpackage ClientSync/Assets/JS/FacetedBooking
 */
import React, { useMemo, useCallback } from 'react';

// THE FIX: The component now receives its data via props passed from its parent, with safe defaults.
const FilterPanel = React.memo(
	( {
		isLoading,
		options,
		selections,
		onSelect,
		onReset,
		availabilityDimensions = {},
		filterOrder = [],
		l10n = {},
		slotCounts = { total: 0, byFilter: {} },
	} ) => {
		const handleCheckboxChange = useCallback(
			( filterKey, value ) => {
				const currentSelection = selections[ filterKey ] || [];
				const newSelection = currentSelection.includes( value )
					? currentSelection.filter( ( item ) => item !== value )
					: [ ...currentSelection, value ];
				onSelect( filterKey, newSelection );
			},
			[ selections, onSelect ]
		);

		const hasSelection = useMemo(
			() =>
				Object.values( selections ).some(
					( val ) => Array.isArray( val ) && val.length > 0
				),
			[ selections ]
		);

		// Calculate total number of active filters
		const activeFilterCount = useMemo( () => {
			return Object.values( selections ).reduce( ( count, val ) => {
				return count + ( Array.isArray( val ) ? val.length : 0 );
			}, 0 );
		}, [ selections ] );

		// Get slot count for a specific filter option
		const getSlotCount = ( filterKey, optionValue ) => {
			const filterCounts = slotCounts.byFilter?.[ filterKey ];
			if ( ! filterCounts ) {
				return null;
			}
			return filterCounts[ optionValue ] ?? 0;
		};

		return (
			<div
				className={ `clisyc-availability-filters-container ${
					isLoading ? 'clisyc-filters-loading' : ''
				}` }
			>
				<div className="clisyc-filter-group-wrapper">
					{ /* Total Slots Counter */ }
					<div className="clisyc-total-slots-container">
						<div className="clisyc-total-slots-badge">
							<span className="clisyc-total-slots-number">
								{ slotCounts.total || 0 }
							</span>
							<span className="clisyc-total-slots-label">
								{ l10n?.slotsInView || 'slots in current view' }
							</span>
						</div>
					</div>

					{ /* Reset All Filters Button */ }
					<div className="clisyc-reset-all-container">
						<button
							type="button"
							className={ `clisyc-reset-all-filters-btn ${
								hasSelection ? 'clisyc-has-selections' : ''
							}` }
							onClick={ onReset }
							disabled={ ! hasSelection }
						>
							<span className="dashicons dashicons-update"></span>
							<span className="clisyc-reset-text">
								{ l10n?.resetAllFilters || 'Reset All Filters' }
								{ activeFilterCount > 0 && (
									<span className="clisyc-active-filter-count">
										({ activeFilterCount })
									</span>
								) }
							</span>
						</button>
					</div>

					{ /* Make sure filterOrder exists before mapping */ }
					{ filterOrder.map( ( key ) => {
						const dim = availabilityDimensions[ key ];
						const currentOptions = options[ key ] || [];
						const hasAvailableOptions = currentOptions.length > 0;

						if ( ! dim ) {
							return null;
						}

						// Count selected items in this filter group
						const selectedCount = ( selections[ key ] || [] )
							.length;

						return (
							<div
								key={ key }
								className="clisyc-filter-group"
								style={ {
									display: hasAvailableOptions
										? 'block'
										: 'none',
								} }
							>
								<h4 className="clisyc-filter-group-label">
									<span
										className={ `dashicons ${
											dim.icon ||
											'dashicons-admin-generic'
										}` }
									></span>
									<span className="clisyc-filter-group-title">
										{ dim.label }
									</span>
									{ selectedCount > 0 && (
										<span className="clisyc-filter-group-selected-count">
											{ selectedCount }
										</span>
									) }
								</h4>
								<div className="clisyc-filter-options-list">
									{ currentOptions.map( ( opt ) => {
										const isChecked = (
											selections[ key ] || []
										).includes( opt.value.toString() );
										const slotCount = getSlotCount(
											key,
											opt.value.toString()
										);

										return (
											<label
												key={ opt.value }
												className={ `clisyc-filter-option-label ${
													isChecked
														? 'clisyc-option-selected'
														: ''
												}` }
											>
												<input
													type="checkbox"
													value={ opt.value }
													checked={ isChecked }
													onChange={ () =>
														handleCheckboxChange(
															key,
															opt.value.toString()
														)
													}
												/>
												{ /* Colored checkbox indicator */ }
												<span
													className="clisyc-filter-checkbox-indicator"
													style={ {
														backgroundColor:
															opt.color ||
															'#9ca3af',
														opacity: isChecked
															? 1
															: 0.4,
													} }
												></span>
												<span className="clisyc-filter-option-text">
													{ opt.label
														.replace(
															/\(\d+\)$/,
															''
														)
														.trim() }
												</span>
												{ /* Slot count badge */ }
												{ slotCount !== null && (
													<span
														className={ `clisyc-filter-slot-count ${
															slotCount === 0
																? 'clisyc-count-zero'
																: ''
														}` }
													>
														{ slotCount }
													</span>
												) }
											</label>
										);
									} ) }
								</div>
							</div>
						);
					} ) }
				</div>
			</div>
		);
	}
);

FilterPanel.displayName = 'HybridFilterPanel';

export default FilterPanel;
