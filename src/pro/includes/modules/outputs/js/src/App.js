/**
 * File: src/pro/includes/modules/outputs/js/src/App.js -> (compiled)
 * The main React component for the Output Template Builder UI.
 *
 * Features:
 *  - Drag-and-drop section reordering via react-beautiful-dnd
 *  - Field palette to add text/table sections
 *  - Starter template selector for new templates
 *  - Placeholder picker dropdown for inserting dynamic tags
 *
 * @package    ClientSyncPro
 * @subpackage ClientSyncPro/Modules/Outputs/JS
 */
import React, { useState, useEffect, useRef, useCallback } from 'react';
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

/**
 * Pre-built starter templates for common notification scenarios.
 */
const STARTER_TEMPLATES = [
    {
        key: 'booking_confirmation',
        label: 'Booking Confirmation',
        icon: 'dashicons-yes-alt',
        description: 'Sent to clients after they book an appointment.',
        triggers: ['new_appointment_client', 'new_appointment_admin'],
        channels: ['email'],
        sections: [
            { id: 'st_1', type: 'text', content: 'Hi {client_first_name}, your appointment has been confirmed!', fields: [] },
            { id: 'st_2', type: 'text', content: 'Date: {appointment_date_formatted}', fields: [] },
            { id: 'st_3', type: 'text', content: 'Time: {appointment_time}', fields: [] },
            { id: 'st_4', type: 'text', content: 'Duration: {appointment_duration} minutes', fields: [] },
            { id: 'st_5', type: 'text', content: 'If you need to make changes, please contact us at {site_name}.', fields: [] },
        ],
    },
    {
        key: 'appointment_reminder',
        label: 'Appointment Reminder',
        icon: 'dashicons-bell',
        description: 'Remind clients of an upcoming appointment.',
        triggers: ['appointment_reminder'],
        channels: ['email'],
        sections: [
            { id: 'st_1', type: 'text', content: 'Hi {client_first_name}, this is a reminder about your upcoming appointment.', fields: [] },
            { id: 'st_2', type: 'text', content: 'Date: {appointment_date_formatted}', fields: [] },
            { id: 'st_3', type: 'text', content: 'Time: {appointment_time}', fields: [] },
            { id: 'st_4', type: 'text', content: 'View details: {appointment_view_link}', fields: [] },
            { id: 'st_5', type: 'text', content: 'We look forward to seeing you!', fields: [] },
        ],
    },
    {
        key: 'cancellation_notice',
        label: 'Cancellation Notice',
        icon: 'dashicons-dismiss',
        description: 'Notify clients that their appointment was cancelled.',
        triggers: ['appointment_cancelled_admin', 'appointment_cancelled_by_client'],
        channels: ['email'],
        sections: [
            { id: 'st_1', type: 'text', content: 'Hi {client_first_name}, your appointment on {appointment_date_formatted} at {appointment_time} has been cancelled.', fields: [] },
            { id: 'st_2', type: 'text', content: 'If this was a mistake or you would like to reschedule, please visit {site_url} or contact us.', fields: [] },
        ],
    },
    {
        key: 'payment_receipt',
        label: 'Payment Receipt',
        icon: 'dashicons-money-alt',
        description: 'Confirm successful payment to the client.',
        triggers: ['payment_successful_client'],
        channels: ['email'],
        sections: [
            { id: 'st_1', type: 'text', content: 'Hi {client_name}, thank you for your payment!', fields: [] },
            { id: 'st_2', type: 'text', content: 'Order #: {order_id}', fields: [] },
            { id: 'st_3', type: 'text', content: 'Amount: {order_total}', fields: [] },
            { id: 'st_4', type: 'text', content: 'Payment method: {order_payment_method_title}', fields: [] },
            { id: 'st_5', type: 'text', content: 'Appointment: {appointment_date_formatted} at {appointment_time}', fields: [] },
            { id: 'st_6', type: 'text', content: 'Thank you for choosing {site_name}!', fields: [] },
        ],
    },
    {
        key: 'admin_new_booking',
        label: 'Admin: New Booking Alert',
        icon: 'dashicons-megaphone',
        description: 'Notify admin when a client books an appointment.',
        triggers: ['new_appointment_client'],
        channels: ['email'],
        sections: [
            { id: 'st_1', type: 'text', content: 'New booking received from {client_name} ({client_email}).', fields: [] },
            { id: 'st_2', type: 'text', content: 'Date: {appointment_date_formatted} at {appointment_time}', fields: [] },
            { id: 'st_3', type: 'text', content: 'Duration: {appointment_duration} minutes', fields: [] },
            { id: 'st_4', type: 'text', content: 'Notes: {appointment_notes}', fields: [] },
            { id: 'st_5', type: 'text', content: 'Edit: {appointment_edit_link_admin}', fields: [] },
        ],
    },
    {
        key: 'blank',
        label: 'Blank Template',
        icon: 'dashicons-plus-alt2',
        description: 'Start from scratch with an empty canvas.',
        triggers: [],
        channels: [],
        sections: [],
    },
];

/**
 * Starter template selection screen. Shown when the builder has no sections.
 */
const StarterPicker = ({ onSelect }) => {
    return (
        <div className="clisyc-starter-picker">
            <h3>Choose a Starting Point</h3>
            <p className="clisyc-starter-subtitle">Select a pre-built template to customize, or start blank.</p>
            <div className="clisyc-starter-grid">
                {STARTER_TEMPLATES.map((tmpl) => (
                    <button
                        key={tmpl.key}
                        type="button"
                        className="clisyc-starter-card"
                        onClick={() => onSelect(tmpl)}
                    >
                        <span className={`dashicons ${tmpl.icon}`}></span>
                        <strong>{tmpl.label}</strong>
                        <span className="clisyc-starter-desc">{tmpl.description}</span>
                    </button>
                ))}
            </div>
        </div>
    );
};

/**
 * Placeholder picker dropdown for inserting dynamic tags into text fields.
 */
const PlaceholderPicker = ({ onInsert }) => {
    const [isOpen, setIsOpen] = useState(false);
    const [filter, setFilter] = useState('');
    const dropdownRef = useRef(null);

    const allPlaceholders = window.clisycOutputBuilderData.placeholders || [];

    const filtered = filter
        ? allPlaceholders.filter(p => p.tag.toLowerCase().includes(filter.toLowerCase()) || p.label.toLowerCase().includes(filter.toLowerCase()))
        : allPlaceholders;

    // Group by category
    const grouped = {};
    filtered.forEach(p => {
        if (!grouped[p.group]) grouped[p.group] = [];
        grouped[p.group].push(p);
    });

    useEffect(() => {
        const handleClickOutside = (e) => {
            if (dropdownRef.current && !dropdownRef.current.contains(e.target)) {
                setIsOpen(false);
                setFilter('');
            }
        };
        if (isOpen) {
            document.addEventListener('mousedown', handleClickOutside);
        }
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, [isOpen]);

    return (
        <div className="clisyc-placeholder-picker" ref={dropdownRef}>
            <button
                type="button"
                className="button button-small clisyc-placeholder-toggle"
                onClick={() => { setIsOpen(!isOpen); setFilter(''); }}
                title="Insert placeholder"
            >
                <span className="dashicons dashicons-shortcode"></span>
                Insert Tag
            </button>
            {isOpen && (
                <div className="clisyc-placeholder-dropdown">
                    <input
                        type="text"
                        className="clisyc-placeholder-search"
                        placeholder="Search placeholders..."
                        value={filter}
                        onChange={(e) => setFilter(e.target.value)}
                        autoFocus
                    />
                    <div className="clisyc-placeholder-list">
                        {Object.keys(grouped).length > 0 ? (
                            Object.entries(grouped).map(([group, items]) => (
                                <div key={group} className="clisyc-placeholder-group">
                                    <div className="clisyc-placeholder-group-label">{group}</div>
                                    {items.map(p => (
                                        <button
                                            key={p.tag}
                                            type="button"
                                            className="clisyc-placeholder-item"
                                            onClick={() => { onInsert(p.tag); setIsOpen(false); setFilter(''); }}
                                        >
                                            <code>{p.tag}</code>
                                            <span>{p.label}</span>
                                        </button>
                                    ))}
                                </div>
                            ))
                        ) : (
                            <p className="clisyc-placeholder-empty">No matching placeholders.</p>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
};

const FieldPalette = ({ onAddField }) => {
    const { fieldTypes } = window.clisycOutputBuilderData;
    return (
        <div className="clisyc-field-palette">
            <h4>Add a Section</h4>
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

/**
 * Mini formatting toolbar for rich text editing.
 */
const FormatToolbar = ({ editorRef }) => {
    const execFormat = (command, value = null) => {
        editorRef.current?.focus();
        document.execCommand(command, false, value);
    };

    const handleLink = () => {
        const url = window.prompt('Enter URL:');
        if (url) {
            execFormat('createLink', url);
        }
    };

    return (
        <div className="clisyc-format-toolbar">
            <button type="button" className="clisyc-format-btn" onClick={() => execFormat('bold')} title="Bold">
                <strong>B</strong>
            </button>
            <button type="button" className="clisyc-format-btn" onClick={() => execFormat('italic')} title="Italic">
                <em>I</em>
            </button>
            <button type="button" className="clisyc-format-btn" onClick={() => execFormat('underline')} title="Underline">
                <u>U</u>
            </button>
            <button type="button" className="clisyc-format-btn" onClick={handleLink} title="Insert Link">
                <span className="dashicons dashicons-admin-links" style={{ fontSize: '14px', width: '14px', height: '14px' }}></span>
            </button>
            <button type="button" className="clisyc-format-btn" onClick={() => execFormat('insertUnorderedList')} title="Bullet List">
                <span className="dashicons dashicons-editor-ul" style={{ fontSize: '14px', width: '14px', height: '14px' }}></span>
            </button>
        </div>
    );
};

const OutputSection = ({ section, index, onUpdate, onDelete }) => {
    const editorRef = useRef(null);
    const tableRef = useRef(null);
    const contentRef = useRef(section.content || '');

    const handleChange = (prop, value) => {
        onUpdate(section.id, { ...section, [prop]: value });
    };

    // Set initial content on mount only — avoids cursor-reset on re-render.
    useEffect(() => {
        if (section.type === 'text' && editorRef.current) {
            editorRef.current.innerHTML = section.content || '';
        }
    }, []); // eslint-disable-line react-hooks/exhaustive-deps

    const handleContentEditableInput = () => {
        if (editorRef.current) {
            contentRef.current = editorRef.current.innerHTML;
            handleChange('content', editorRef.current.innerHTML);
        }
    };

    const handleInsertPlaceholder = (tag) => {
        if (section.type === 'text') {
            const el = editorRef.current;
            if (!el) return;
            el.focus();
            const sel = window.getSelection();
            if (sel.rangeCount) {
                const range = sel.getRangeAt(0);
                range.deleteContents();
                range.insertNode(document.createTextNode(tag));
                range.collapse(false);
                sel.removeAllRanges();
                sel.addRange(range);
            } else {
                el.innerHTML += tag;
            }
            handleChange('content', el.innerHTML);
        } else {
            const el = tableRef.current;
            if (!el) return;
            const start = el.selectionStart || 0;
            const end = el.selectionEnd || 0;
            const currentVal = section.fields?.join('\n') || '';
            const newVal = currentVal.substring(0, start) + tag + currentVal.substring(end);
            handleChange('fields', newVal.split('\n'));
            requestAnimationFrame(() => {
                el.focus();
                const newPos = start + tag.length;
                el.setSelectionRange(newPos, newPos);
            });
        }
    };

    return (
        <Draggable draggableId={section.id} index={index}>
            {(provided, snapshot) => (
                <div ref={provided.innerRef} {...provided.draggableProps} className={`clisyc-output-section ${snapshot.isDragging ? 'is-dragging' : ''}`}>
                    <div className="clisyc-section-header" {...provided.dragHandleProps}>
                        <span className="clisyc-section-drag-icon dashicons dashicons-move"></span>
                        <span className="clisyc-section-type">{section.type}</span>
                        <PlaceholderPicker onInsert={handleInsertPlaceholder} />
                        <button type="button" className="clisyc-section-delete" onClick={() => onDelete(section.id)}>
                            <span className="dashicons dashicons-trash"></span>
                        </button>
                    </div>
                    {section.type === 'text' && (
                        <>
                            <FormatToolbar editorRef={editorRef} />
                            <div
                                ref={editorRef}
                                className="clisyc-richtext-editor"
                                contentEditable
                                onInput={handleContentEditableInput}
                                suppressContentEditableWarning
                            />
                        </>
                    )}
                    {section.type === 'table' && (
                        <textarea
                            ref={tableRef}
                            value={section.fields?.join('\n') || ''}
                            onChange={(e) => handleChange('fields', e.target.value.split('\n'))}
                            placeholder="One field per line"
                        />
                    )}
                </div>
            )}
        </Draggable>
    );
};

const App = () => {
    const [sections, setSections] = useState(() => {
        if (window.clisycOutputBuilderData && window.clisycOutputBuilderData.formJson) {
            try {
                const parsed = JSON.parse(window.clisycOutputBuilderData.formJson);
                // Handle both array format and {sections: [...]} format
                const initialSections = Array.isArray(parsed) ? parsed : (parsed.sections || []);
                return initialSections;
            } catch (e) {
                console.error('[Client Sync Output Builder] JSON parse error:', e);
            }
        }
        return [];
    });

    const [confirmState, setConfirmState] = useState(null);

    // Track whether user has dismissed the starter picker (chose a template or went blank)
    const [showStarter, setShowStarter] = useState(() => {
        // Show starter only on brand-new templates (no saved JSON, no sections)
        const raw = window.clisycOutputBuilderData?.formJson || '[]';
        try {
            const parsed = JSON.parse(raw);
            const secs = Array.isArray(parsed) ? parsed : (parsed.sections || []);
            return secs.length === 0;
        } catch {
            return true;
        }
    });

    const outputTextarea = document.getElementById('clisyc-output-json-output');

    useEffect(() => {
        if (outputTextarea) {
            const json = { sections };
            outputTextarea.value = JSON.stringify(json, null, 2);
        }
    }, [sections]);

    const handleSelectStarter = (template) => {
        // Assign unique IDs to the starter sections
        const newSections = template.sections.map((s, i) => ({
            ...s,
            id: `section_${Date.now()}_${i}`,
        }));
        setSections(newSections);
        setShowStarter(false);

        // Auto-select triggers in the sidebar metabox
        if (template.triggers?.length) {
            document.querySelectorAll('input[name="clisyc_triggers[]"]').forEach((cb) => {
                cb.checked = template.triggers.includes(cb.value);
            });
        }

        // Auto-select channels in the sidebar metabox
        if (template.channels?.length) {
            document.querySelectorAll('input[name="clisyc_channels[]"]').forEach((cb) => {
                cb.checked = template.channels.includes(cb.value);
            });
        }
    };

    const handleAddSection = (type) => {
        const newSection = {
            id: `section_${Date.now()}`,
            type,
            content: type === 'text' ? '' : '',
            fields: type === 'table' ? ['Field 1', 'Field 2'] : [],
        };
        setSections([...sections, newSection]);
    };

    const handleUpdateSection = (id, updatedSection) => {
        setSections(sections.map(s => (s.id === id ? updatedSection : s)));
    };

    const handleDeleteSection = useCallback((id) => {
        setConfirmState({
            message: 'Are you sure you want to delete this section?',
            onConfirm: () => {
                setSections(prev => prev.filter(s => s.id !== id));
                setConfirmState(null);
            },
        });
    }, []);

    const onDragEnd = (result) => {
        if (!result.destination) return;
        const items = Array.from(sections);
        const [reorderedItem] = items.splice(result.source.index, 1);
        items.splice(result.destination.index, 0, reorderedItem);
        setSections(items);
    };

    // If this is a brand new template with no sections, show the starter picker
    if (showStarter && sections.length === 0) {
        return (
            <div className="clisyc-output-builder">
                <StarterPicker onSelect={handleSelectStarter} />
            </div>
        );
    }

    return (
        <div className="clisyc-output-builder">
            {confirmState && (
                <ConfirmDialog
                    message={confirmState.message}
                    onConfirm={confirmState.onConfirm}
                    onCancel={() => setConfirmState(null)}
                />
            )}
            <FieldPalette onAddField={handleAddSection} />
            <DragDropContext onDragEnd={onDragEnd}>
                <Droppable droppableId="output-canvas">
                    {(provided) => (
                        <div {...provided.droppableProps} ref={provided.innerRef} className="clisyc-output-canvas">
                            {sections.length > 0 ? (
                                sections.map((section, index) => (
                                    <OutputSection
                                        key={section.id}
                                        section={section}
                                        index={index}
                                        onUpdate={handleUpdateSection}
                                        onDelete={handleDeleteSection}
                                    />
                                ))
                            ) : (
                                <p style={{ textAlign: 'center', marginTop: '50px', color: '#787c82' }}>
                                    Use the sidebar to add sections to your template.
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
