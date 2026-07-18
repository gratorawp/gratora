/**
 * Prettier config: the WordPress ruleset, but 4-space indentation instead of
 * tabs to match the existing codebase. Single source for both `wp-scripts
 * format` (reads this directly) and the `prettier/prettier` lint rule
 * (@wordpress/eslint-plugin merges this over @wordpress/prettier-config).
 */
module.exports = {
    ...require( '@wordpress/prettier-config' ),
    useTabs: false,
};
