import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

import Notice from '../_shared/components/Notice';

const REVIEWS_URL = 'https://wordpress.org/support/plugin/gratora-donation-platform/reviews/#new-post';

/**
 * Asks for a review once, where the dashboard says it is due. Any answer ends
 * it here; the server keeps the answer so it stays ended.
 */
export default function ReviewPrompt( { onAnswered } ) {
    const answer = ( value ) => {
        apiFetch( { path: '/gratora/v1/admin/me/review-prompt', method: 'POST', data: { answer: value } } ).catch( () => {} );
        onAnswered();
    };

    return (
        <Notice status="info" onRemove={ () => answer( 'later' ) }>
            { __( 'Your first donations have come in through Gratora. If it is working for you, a review on WordPress.org helps other organizations find it.', 'gratora-donation-platform' ) }
            { ' ' }
            <Button variant="link" href={ REVIEWS_URL } target="_blank" rel="noopener noreferrer" onClick={ () => answer( 'reviewed' ) }>
                { __( 'Leave a review', 'gratora-donation-platform' ) }
            </Button>
            { ' · ' }
            <Button variant="link" onClick={ () => answer( 'later' ) }>
                { __( 'Maybe later', 'gratora-donation-platform' ) }
            </Button>
            { ' · ' }
            <Button variant="link" onClick={ () => answer( 'never' ) }>
                { __( 'Do not ask again', 'gratora-donation-platform' ) }
            </Button>
        </Notice>
    );
}
