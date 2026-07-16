/**
 * Forms-editor block registry. Two extension surfaces converge here:
 * window.dono.blocks.register(name, def) after the bundle loads, and the
 * 'dono.editor.registerBlocks' action fired before mount; both whitelist for the inserter.
 */

import { registerBlockType, getBlockType } from '@wordpress/blocks';
import { doAction, addAction } from '@wordpress/hooks';

const allowed = new Set();

/**
 * Defaults merged into every Dono block's `supports` at registration time.
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
            throw new Error( 'dono.blocks.register: name must be a non-empty string' );
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
};

/** Fire the registration action; called once by Editor.jsx on mount. */
export function runBlockRegistration() {
    doAction( 'dono.editor.registerBlocks', api );
}

// Expose on window.dono for the imperative path.
if ( typeof window !== 'undefined' ) {
    window.dono = window.dono || {};
    window.dono.blocks = api;
    window.dono.editorHooks = {
        registerBlocks: 'dono.editor.registerBlocks',
    };
}

export { addAction, doAction };
export default api;
