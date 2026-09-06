const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
    ...defaultConfig,
    entry: {
        'admin/dashboard':         path.resolve( __dirname, 'assets/admin/dashboard/index.jsx' ),
        'admin/settings':          path.resolve( __dirname, 'assets/admin/settings/index.jsx' ),
        'admin/campaigns':         path.resolve( __dirname, 'assets/admin/campaigns/index.jsx' ),
        'admin/donations':         path.resolve( __dirname, 'assets/admin/donations/index.jsx' ),
        'admin/donors':            path.resolve( __dirname, 'assets/admin/donors/index.jsx' ),
        'admin/forms':             path.resolve( __dirname, 'assets/admin/forms/index.jsx' ),
        'admin/funds':             path.resolve( __dirname, 'assets/admin/funds/index.jsx' ),
        'admin/subscriptions':     path.resolve( __dirname, 'assets/admin/subscriptions/index.jsx' ),
        'admin/tools':             path.resolve( __dirname, 'assets/admin/tools/index.jsx' ),
        'admin/onboarding':        path.resolve( __dirname, 'assets/admin/onboarding/index.jsx' ),
        'admin/campaign-blocks':   path.resolve( __dirname, 'assets/admin/campaign-blocks/index.jsx' ),
        'admin/campaign-blocks-ui': path.resolve( __dirname, 'assets/admin/campaign-blocks/editor-ui.js' ),
        'admin/command-palette':   path.resolve( __dirname, 'assets/admin/command-palette/index.jsx' ),
        'donation-form/runtime':   path.resolve( __dirname, 'assets/donation-form/runtime.jsx' ),
        'donor-portal/index':      path.resolve( __dirname, 'assets/donor-portal/index.jsx' ),
    },
    output: {
        ...defaultConfig.output,
        path: path.resolve( __dirname, 'build' ),
        filename: '[name]/index.js',
    },
    module: {
        ...defaultConfig.module,
        rules: [
            {
                enforce: 'pre',
                test: /node_modules[\\/]@fundkit[\\/]ui[\\/]dist[\\/].+\.js$/,
                loader: path.resolve( __dirname, 'build-tools/fundkitUiDomain.cjs' ),
            },
            ...( ( defaultConfig.module && defaultConfig.module.rules ) || [] ),
        ],
    },
    resolve: {
        ...( defaultConfig.resolve || {} ),
        alias: {
            ...( ( defaultConfig.resolve && defaultConfig.resolve.alias ) || {} ),
            react:       'preact/compat',
            'react-dom': 'preact/compat',
        },
    },
};
