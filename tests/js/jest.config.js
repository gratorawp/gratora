/**
 * Front-end unit suite. The PHP suites cannot reach what a donor sees after a
 * gateway sends their browser away and back, and that is where this group's
 * defects live.
 *
 * Run with: npx jest -c tests/js/jest.config.js
 *
 * Lives under tests/ because .distignore already keeps that whole tree out of
 * the published zip.
 */
const path = require( 'path' );

const root = path.resolve( __dirname, '../..' );

module.exports = {
    rootDir:        root,
    testEnvironment: 'jsdom',
    // jsdom otherwise resolves the browser condition, which hands Jest the
    // untranspiled ESM build of preact and friends.
    testEnvironmentOptions: { customExportConditions: [ 'node', 'require', 'default' ] },
    testMatch:      [ '<rootDir>/tests/js/**/*.test.js' ],
    transformIgnorePatterns: [ '/node_modules/(?!(preact|@preact|@wordpress|@dono)/)' ],
    transform: {
        '\\.[jt]sx?$': [ 'babel-jest', { configFile: path.join( __dirname, 'babel.config.js' ) } ],
    },
    moduleNameMapper: {
        '\\.s?css$': path.join( __dirname, 'styleMock.js' ),
    },
};
