/** @jsxImportSource preact */

import { Component } from 'preact';

// Contains a render/lifecycle error to the wrapped subtree so one broken
// block can't take down the whole form (which would otherwise fall back to
// the unstyled server HTML).
export default class ErrorBoundary extends Component {
    constructor( props ) {
        super( props );
        this.state = { failed: false };
    }

    componentDidCatch( error ) {
        // eslint-disable-next-line no-console
        console.error( '[dono] render error contained by boundary', error );
        this.setState( { failed: true } );
    }

    render() {
        if ( this.state.failed ) {
            return this.props.fallback || null;
        }
        return this.props.children;
    }
}
