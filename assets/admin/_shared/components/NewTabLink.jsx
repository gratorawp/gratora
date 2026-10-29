import { __ } from '@wordpress/i18n';
import { ExternalLink } from 'lucide-react';

export default function NewTabLink( { href, children } ) {
    return (
        <a href={ href } target="_blank" rel="noopener noreferrer">
            { children }
            <ExternalLink size={ 12 } strokeWidth={ 2 } aria-hidden="true" />
            <span className="screen-reader-text">{ __( '(opens in a new tab)', 'gratora-donation-platform' ) }</span>
        </a>
    );
}
