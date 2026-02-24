/**
 * Mock for @wordpress/i18n — pass-through translations.
 */
module.exports = {
	__: (text) => text,
	_x: (text) => text,
	_n: (single, plural, number) => (number === 1 ? single : plural),
	_nx: (single, plural, number) => (number === 1 ? single : plural),
	sprintf: (format, ...args) => {
		let i = 0;
		return format.replace(/%[sd]/g, () => args[i++] ?? '');
	},
};
