// Enough to load the plugin's own ESM (and the preact JSX its pragmas name)
// under Jest. Node runs the tests, so no browser targets are needed.
module.exports = {
    presets: [
        [ require.resolve( '@babel/preset-env' ), { targets: { node: 'current' } } ],
        [ require.resolve( '@babel/preset-react' ), { runtime: 'automatic', importSource: 'preact' } ],
    ],
};
