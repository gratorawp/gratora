/**
 * Forms-editor block registry. Two extension surfaces converge here:
 * window.fundkit.blocks.register(name, def) after the bundle loads, and the
 * 'fundkit.editor.registerBlocks' action fired before mount; both whitelist for the inserter.
 */

import { registerBlockType, getBlockType } from '@wordpress/blocks';
import { doAction, addAction } from '@wordpress/hooks';

import { ConditionPanel, DEFAULT_CONDITION } from './blocks/_shared/condition';
import { BlockIcons } from './blocks/_shared/block-icons';

const allowed = new Set();

/**
 * Defaults merged into every FundKit block's `supports` at registration time.
 * Keeps Gutenberg's "Advanced > Additional CSS class(es)" panel hidden:
 * donation-form authors shouldn't be hand-rolling CSS classes per block.
 */
const SUPPORTS_DEFAULTS = {
    customClassName: false,
};

const api = {
    /** Register a block. Idempotent. */
    register( name, definition ) {
        if ( ! name || typeof name !== 'string' ) {
            throw new Error( 'fundkit.blocks.register: name must be a non-empty string' );
        }
        allowed.add( name );
        const merged = {
            ...definition,
            supports: { ...SUPPORTS_DEFAULTS, ...( definition.supports || {} ) },
        };
        return getBlockType( name ) ?? registerBlockType( name, merged );
    },

    /** @returns {string[]} Names currently registered. */
    get allowed() {
        return Array.from( allowed );
    },

    has( name ) {
        return allowed.has( name );
    },

    // Handed to add-on blocks so a field outside core gets the same
    // conditional-logic panel and icon set as the built-ins, instead of
    // reimplementing them and drifting.
    shared: { ConditionPanel, DEFAULT_CONDITION, BlockIcons },
};

/** Fire the registration action; called once by Editor.jsx on mount. */
export function runBlockRegistration() {
    doAction( 'fundkit.editor.registerBlocks', api );
}

// Expose on window.fundkit for the imperative path.
if ( typeof window !== 'undefined' ) {
    window.fundkit = window.fundkit || {};
    window.fundkit.blocks = api;
    window.fundkit.editorHooks = {
        registerBlocks: 'fundkit.editor.registerBlocks',
    };
}

export { addAction, doAction };
export default api;
