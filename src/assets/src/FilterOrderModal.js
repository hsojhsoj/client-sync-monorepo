/**
 * File: src/assets/src/FilterOrderModal.js -> client-sync/assets/src/FilterOrderModal.js
 * Filter Order Modal Component
 *
 * This file contains the React component for the "Edit Filter Order" modal. It
 * displays a draggable list of enabled dimensions, allowing the user to visually
 * reorder them. The new order is passed back to the main app via the `onUpdate` callback.
 * REFACTORED: Prefixes updated to 'clisyc' to meet WordPress.org standards.
 */
import React, { useState, useEffect, useRef } from 'react';

const FilterOrderModal = ({ isOpen, onClose, initialOrder, nodes, onUpdate }) => {
    const [orderedItems, setOrderedItems] = useState([]);
    const dragItem = useRef(null);
    const dragOverItem = useRef(null);

    useEffect(() => {
        if (!isOpen) return;

        const enabledNodes = nodes.map(node => ({
            slug: node.id,
            label: node.data.label,
            icon: node.data.icon,
        }));

        const sorted = [...initialOrder]
            .map(slug => enabledNodes.find(n => n.slug === slug))
            .filter(Boolean);

        const unsorted = enabledNodes.filter(n => !initialOrder.includes(n.slug));

        setOrderedItems([...sorted, ...unsorted]);
    }, [initialOrder, nodes, isOpen]);

    if (!isOpen) {
        return null;
    }

    const handleDragStart = (e, index) => {
        dragItem.current = index;
        e.target.classList.add('is-dragging');
    };

    const handleDragEnter = (e, index) => {
        dragOverItem.current = index;
        const listItems = e.target.closest('ul').children;
        for (let item of listItems) {
            item.classList.remove('drag-over');
        }
        e.target.closest('li').classList.add('drag-over');
    };
    
    const handleDragEnd = (e) => {
        e.target.classList.remove('is-dragging');
        const listItems = e.target.closest('ul').children;
        for (let item of listItems) {
            item.classList.remove('drag-over');
        }

        if (dragItem.current !== null && dragOverItem.current !== null && dragItem.current !== dragOverItem.current) {
            const newItems = [...orderedItems];
            const draggedItemContent = newItems.splice(dragItem.current, 1)[0];
            newItems.splice(dragOverItem.current, 0, draggedItemContent);
            setOrderedItems(newItems);
        }

        dragItem.current = null;
        dragOverItem.current = null;
    };

    const handleSave = () => {
        onUpdate(orderedItems.map(item => item.slug));
        onClose();
    };

    // *** REFACTORED: Updated all clisyc- CSS classes ***
    return (
        <div className="clisyc-node-modal-overlay">
            <div className="clisyc-node-modal">
                <h2>Edit Filter Order</h2>
                <p>Drag and drop to reorder the filters for the frontend booking calendar.</p>
                <ul className="clisyc-filter-order-modal-list">
                    {orderedItems.map((item, index) => (
                        <li
                            key={item.slug}
                            className="clisyc-filter-order-modal-item"
                            draggable
                            onDragStart={(e) => handleDragStart(e, index)}
                            onDragEnter={(e) => handleDragEnter(e, index)}
                            onDragEnd={handleDragEnd}
                            onDragOver={(e) => e.preventDefault()}
                        >
                            <span className="clisyc-drag-handle"></span>
                            <span className={`clisyc-node-icon dashicons ${item.icon || 'dashicons-admin-generic'}`}></span>
                            {item.label}
                        </li>
                    ))}
                </ul>
                <div className="clisyc-node-modal-actions">
                    <button type="button" className="button" onClick={onClose}>Cancel</button>
                    <button type="button" className="button button-primary" onClick={handleSave}>Save Order</button>
                </div>
            </div>
        </div>
    );
};

export default FilterOrderModal;