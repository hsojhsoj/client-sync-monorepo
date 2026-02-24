/**
 * Mock for @wordpress/components.
 * Provides stub versions of commonly used components.
 */
const React = require('react');

module.exports = {
	Button: (props) => React.createElement('button', props, props.children),
	Spinner: () => React.createElement('span', { className: 'components-spinner' }, 'Loading...'),
	TextControl: (props) => React.createElement('input', { type: 'text', ...props }),
	SelectControl: (props) => React.createElement('select', props, props.children),
	Modal: (props) => React.createElement('div', { className: 'components-modal' }, props.children),
	Notice: (props) => React.createElement('div', { className: 'components-notice' }, props.children),
	Panel: (props) => React.createElement('div', props, props.children),
	PanelBody: (props) => React.createElement('div', props, props.children),
	PanelRow: (props) => React.createElement('div', props, props.children),
};
