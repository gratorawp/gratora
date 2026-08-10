/**
 * ESLint config. Mirrors wp-scripts' default (@wordpress/eslint-plugin) but
 * turns off Prettier + brace-style enforcement: the codebase uses a deliberate
 * manual format (aligned columns, 4-space indent, single-line guards) that
 * Prettier cannot preserve, so enforcing it would mean reformatting every file.
 * lint:js still runs the substantive rules (unused vars, hooks, a11y, etc.).
 */
module.exports = {
    root: true,
    extends: [ 'plugin:@wordpress/eslint-plugin/recommended' ],
    env: { browser: true },
    // The JS lint uses the babel parser, which can't parse TypeScript: the
    // Cloudflare Worker (broker/) and Playwright specs (tests-e2e/) are .ts and
    // report every type reference as undefined. They need their own TS lint;
    // don't let them drown lint:js. build/ and vendor* are generated.
    ignorePatterns: [ 'build/', 'vendor/', 'vendor/vendor-prefixed/', 'broker/', 'tests-e2e/', '**/*.ts', '**/*.tsx' ],
    // No babel config in the repo, so mirror wp-scripts' parser fallback.
    parserOptions: {
        requireConfigFile: false,
        babelOptions: {
            presets: [ require.resolve( '@wordpress/babel-preset-default' ) ],
        },
    },
    rules: {
        'prettier/prettier': 'off',
        curly: 'off',
        // House style diverges from the WP defaults by design; keep lint focused
        // on substance (unused vars, hooks, a11y) rather than these conventions:
        camelcase: 'off', //           REST/DB fields are snake_case (amount_cents)
        'dot-notation': 'off',
        'no-nested-ternary': 'off',
        // `onChange && onChange( v )` is the runtime's optional-callback idiom.
        'no-unused-expressions': [ 'error', { allowShortCircuit: true, allowTernary: true } ],
        // House rule bans en/em dashes; keep hyphens in numeric ranges.
        '@wordpress/i18n-hyphenated-range': 'off',
        // The forms wrap controls inside their <label> (valid implicit
        // association per the html spec); accept that, not only htmlFor.
        // The custom components below render the actual control element.
        'jsx-a11y/label-has-associated-control': [ 'error', {
            assert: 'either',
            depth: 3, // label text is often wrapped in a styled span/strong
            controlComponents: [ 'AmountInput', 'CountrySelect', 'Switch' ],
        } ],
        // `x == null` is the codebase's deliberate null-or-undefined guard;
        // require === everywhere else.
        eqeqeq: [ 'error', 'always', { null: 'ignore' } ],
        // autofocus is used only inside modals/drawers the user just opened
        // (focus belongs in the dialog) and as an opt-in prop, never on load.
        'jsx-a11y/no-autofocus': 'off',
        // the form editor embeds WP block-editor components (BlockLibrary,
        // ListView, NumberControl) that ship only as __experimental with no
        // stable equivalent.
        '@wordpress/no-unsafe-wp-apis': 'off',
        'jsdoc/require-param': 'off', // comments are intentionally minimal
        'jsdoc/require-param-type': 'off',
        'jsdoc/check-tag-names': 'off', // e.g. @jsxImportSource in the Preact runtime
    },
    overrides: [
        {
            // Unit-test files (mirrors wp-scripts' default override).
            files: [ '**/@(test|__tests__)/**/*.js', '**/?(*.)test.js' ],
            extends: [ 'plugin:@wordpress/eslint-plugin/test-unit' ],
        },
        {
            // Preact runtimes: `class`/`for` JSX attributes are correct here and
            // React-specific rules don't apply.
            files: [ 'assets/donation-form/**', 'assets/donor-portal/**' ],
            rules: {
                'react/no-unknown-property': 'off',
                'react/no-unescaped-entities': 'off',
            },
        },
    ],
};
