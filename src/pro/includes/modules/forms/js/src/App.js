/**
 * File: src/pro/includes/modules/forms/js/src/App.js
 */
import React, { useState, useEffect, useCallback } from 'react';
import { DragDropContext, Droppable, Draggable } from 'react-beautiful-dnd';

const ConfirmDialog = ({ message, onConfirm, onCancel }) => (
    <div className="clisyc-confirm-overlay" onClick={onCancel}>
        <div className="clisyc-confirm-dialog" onClick={(e) => e.stopPropagation()}>
            <p>{message}</p>
            <div className="clisyc-confirm-actions">
                <button type="button" className="button button-primary" onClick={onConfirm}>Delete</button>
                <button type="button" className="button" onClick={onCancel}>Cancel</button>
            </div>
        </div>
    </div>
);

const FieldPalette = ({ onAddField }) => {
    const { fieldTypes } = window.clisycFormBuilderData;
    return (
        <div className="clisyc-field-palette">
            <h4>Add a Field</h4>
            {Object.entries(fieldTypes).map(([type, { label, icon }]) => (
                <button
                    key={type}
                    type="button"
                    className="button clisyc-field-palette-btn"
                    onClick={() => onAddField(type)}
                >
                    <span className={`dashicons ${icon}`}></span>
                    {label}
                </button>
            ))}
        </div>
    );
};

const FormField = ({ field, index, onUpdate, onDelete }) => {
    const handleFieldChange = (updates) => {
        onUpdate(field.id, updates);
    };

    const handleSelectImage = () => {
        const mediaFrame = wp.media({
            title: 'Select Image for Map',
            button: { text: 'Use this Image' },
            library: { type: 'image' },
            multiple: false,
        });

        mediaFrame.on('select', () => {
            const attachment = mediaFrame.state().get('selection').first().toJSON();
            const previewUrl = attachment.sizes?.medium?.url || attachment.url;
            handleFieldChange({ 
                image_id: attachment.id,
                image_url: previewUrl 
            });
        });

        mediaFrame.open();
    };

    return (
        <Draggable draggableId={field.id} index={index}>
            {(provided, snapshot) => (
                <div
                    ref={provided.innerRef}
                    {...provided.draggableProps}
                    className={`clisyc-builder-field ${snapshot.isDragging ? 'is-dragging' : ''} clisyc-field-width-${field.width || 'full'}`}
                >
                    <div className="clisyc-field-header" {...provided.dragHandleProps}>
                        <span className="clisyc-field-drag-icon dashicons dashicons-move"></span>
                        <span className="clisyc-field-type-label">{field.type}</span>
                        <div className="clisyc-field-actions">
                            <button type="button" className="clisyc-field-delete" onClick={() => onDelete(field.id)}>
                                <span className="dashicons dashicons-trash"></span>
                            </button>
                        </div>
                    </div>
                    <div className="clisyc-form-field-grid">
                        {field.type !== 'html' && field.type !== 'user_account' && (
                            <div className="clisyc-field-config-item">
                                <label>Field Label</label>
                                <input type="text" className="widefat" value={field.label || ''} onChange={(e) => handleFieldChange({ label: e.target.value })} />
                            </div>
                        )}
                        
                        {field.type !== 'user_account' && (
                            <div className="clisyc-field-config-item">
                               <label>Field Width</label>
                                <select value={field.width || 'full'} onChange={(e) => handleFieldChange({ width: e.target.value })} className="widefat">
                                    <option value="full">Full Width</option>
                                    <option value="half">Half Width</option>
                                    <option value="one-third">One-Third Width</option>
                                    <option value="two-thirds">Two-Thirds Width</option>
                                </select>
                            </div>
                        )}

                        {field.type !== 'html' && field.type !== 'image_map' && field.type !== 'user_account' && (
                            <div className="clisyc-field-config-item">
                                <label>
                                    <input type="checkbox" checked={!!field.required} onChange={(e) => handleFieldChange({ required: e.target.checked })} />
                                    Required?
                                </label>
                            </div>
                        )}
                        
                        {['radio', 'multi_checkbox'].includes(field.type) && (
                            <div className="clisyc-field-config-item clisyc-layout-control">
                                <label>Layout</label>
                                <div>
                                    <label style={{ marginRight: '15px' }}>
                                        <input type="radio" value="vertical" checked={!field.layout || field.layout === 'vertical'} onChange={() => handleFieldChange({ layout: 'vertical' })} /> Vertical
                                    </label>
                                    <label>
                                        <input type="radio" value="horizontal" checked={field.layout === 'horizontal'} onChange={() => handleFieldChange({ layout: 'horizontal' })} /> Horizontal
                                    </label>
                                </div>
                            </div>
                        )}

                        {['select', 'radio', 'multi_checkbox'].includes(field.type) && (
                            <div className="clisyc-option-editor clisyc-field-config-item">
                                <label>Options (one per line)</label>
                                <textarea className="widefat" value={(field.options || []).join('\n')} onChange={(e) => handleFieldChange({ options: e.target.value.split('\n') })}></textarea>
                            </div>
                        )}

                        {field.type === 'html' && (
                             <div className="clisyc-option-editor clisyc-field-config-item">
                                <label>HTML Content</label>
                                <textarea className="widefat" style={{ height: '120px' }} value={field.html_content || ''} onChange={(e) => handleFieldChange({ html_content: e.target.value })}></textarea>
                            </div>
                        )}

                        {field.type === 'image_map' && (
                            <div className="clisyc-option-editor clisyc-field-config-item">
                                <label>Image</label>
                                {field.image_url && <img src={field.image_url} alt="Preview" style={{ maxWidth: '100%', height: 'auto', border: '1px solid #ddd', marginBottom: '5px' }} />}
                                <button type="button" className="button" onClick={handleSelectImage}>Select Image</button>
                            </div>
                        )}

                        {field.type === 'user_account' && (
                            <div className="clisyc-field-config-item" style={{ gridColumn: '1 / -1' }}>
                                <p><em>This block will add Email, Username, and Password fields to your form for user registration.</em></p>
                                <label style={{marginTop: '10px'}}>
                                    <input type="checkbox" checked={!!field.autoLogin} onChange={(e) => handleFieldChange({ autoLogin: e.target.checked })} />
                                    Log user in after registration?
                                </label>
                            </div>
                        )}
                    </div>
                </div>
            )}
        </Draggable>
    );
};

const App = () => {
    const [fields, setFields] = useState(() => {
        if (window.clisycFormBuilderData && window.clisycFormBuilderData.formJson) {
            try {
                const initialFields = JSON.parse(window.clisycFormBuilderData.formJson);
                if (Array.isArray(initialFields)) return initialFields;
            } catch (e) { console.error(e); }
        }
        return [];
    });
    const [notice, setNotice] = useState(null);
    const [confirmState, setConfirmState] = useState(null);

    const outputTextarea = document.getElementById('clisyc-form-builder-json-output');

    useEffect(() => {
        if (outputTextarea) {
            outputTextarea.value = JSON.stringify(fields, null, 2);
        }
    }, [fields]);

    useEffect(() => {
        if (!notice) return;
        const timer = setTimeout(() => setNotice(null), 4000);
        return () => clearTimeout(timer);
    }, [notice]);

    const handleAddField = (type) => {
        const hasUserAccountField = fields.some(f => f.type === 'user_account');
        if (type === 'user_account' && hasUserAccountField) {
            setNotice('A form can only have one User Account block.');
            return;
        }

        const newField = {
            id: `field_${new Date().getTime()}`,
            type,
            label: `New ${type.charAt(0).toUpperCase() + type.slice(1)} Field`,
            required: false,
            width: 'full',
        };
        if (['select', 'radio', 'multi_checkbox'].includes(type)) {
            newField.options = ['Option 1', 'Option 2'];
            newField.layout = 'vertical';
        }
        if (type === 'html') {
            newField.html_content = '<h3>Custom Heading</h3><p>This is your custom HTML content.</p>';
            newField.label = 'Custom HTML Block';
        }
        if (type === 'user_account') {
            newField.label = 'User Registration';
            newField.autoLogin = true;
        }
        setFields([...fields, newField]);
    };

    const handleUpdateField = (id, updates) => {
        setFields(prevFields => 
            prevFields.map(f => (f.id === id ? { ...f, ...updates } : f))
        );
    };

    const handleDeleteField = useCallback((id) => {
        setConfirmState({
            message: 'Are you sure you want to delete this field?',
            onConfirm: () => {
                setFields(prev => prev.filter(f => f.id !== id));
                setConfirmState(null);
            },
        });
    }, []);

    const onDragEnd = (result) => {
        if (!result.destination) return;
        const items = Array.from(fields);
        const [reorderedItem] = items.splice(result.source.index, 1);
        items.splice(result.destination.index, 0, reorderedItem);
        setFields(items);
    };

    return (
        <div className="clisyc-form-builder">
            {notice && (
                <div className="notice notice-warning clisyc-builder-notice">
                    <p>{notice}</p>
                </div>
            )}
            {confirmState && (
                <ConfirmDialog
                    message={confirmState.message}
                    onConfirm={confirmState.onConfirm}
                    onCancel={() => setConfirmState(null)}
                />
            )}
            <FieldPalette onAddField={handleAddField} />
            <DragDropContext onDragEnd={onDragEnd}>
                <Droppable droppableId="form-canvas">
                    {(provided) => (
                        <div {...provided.droppableProps} ref={provided.innerRef} className="clisyc-form-canvas">
                            {fields.length > 0 ? (
                                fields.map((field, index) => (
                                    <FormField
                                        key={field.id} field={field} index={index}
                                        onUpdate={handleUpdateField} onDelete={handleDeleteField}
                                    />
                                ))
                            ) : (
                                <p style={{ textAlign: 'center', marginTop: '50px', color: '#787c82' }}>
                                    Add fields from the left panel to begin building your form.
                                </p>
                            )}
                            {provided.placeholder}
                        </div>
                    )}
                </Droppable>
            </DragDropContext>
        </div>
    );
};

export default App;