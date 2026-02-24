const defaultConfig = require('@wordpress/scripts/config/jest-unit.config');

module.exports = {
  ...defaultConfig,
  // Automatically load the setup file before each test
  setupFilesAfterEnv: ['<rootDir>/jest-setup.js'],

  // Map WordPress packages to their browser globals or mocks.
  moduleNameMapper: {
    ...( defaultConfig.moduleNameMapper || {} ),
    '^@wordpress/element$': '<rootDir>/tests/mocks/wordpress-element.js',
    '^@wordpress/components$': '<rootDir>/tests/mocks/wordpress-components.js',
    '^@wordpress/i18n$': '<rootDir>/tests/mocks/wordpress-i18n.js',
    '^@wordpress/api-fetch$': '<rootDir>/tests/mocks/wordpress-api-fetch.js',
  },
};