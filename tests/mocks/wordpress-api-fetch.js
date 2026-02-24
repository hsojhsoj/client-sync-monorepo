/**
 * Mock for @wordpress/api-fetch — jest.fn() so tests can set implementations.
 */
const apiFetch = jest.fn();
module.exports = apiFetch;
module.exports.default = apiFetch;
