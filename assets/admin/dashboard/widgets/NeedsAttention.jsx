import { __ } from '@wordpress/i18n';

export default function NeedsAttention( { items = [] } ) {
    if ( items.length === 0 ) {
        return (
            <p className="dono-attention__empty">
                { __( 'Nothing needs attention right now.', 'dono' ) }
            </p>
        );
    }

    return (
        <ul className="dono-attention">
            { items.map( ( item ) => (
                <li key={ item.key } className={ `dono-attention__item is-${ item.tone }` }>
                    <span className="dono-attention__dot" aria-hidden="true" />
                    <span className="dono-attention__title">{ item.title }</span>
                    { item.action_href && (
                        <a className="dono-attention__action" href={ item.action_href }>
                            { item.action_label || __( 'Open', 'dono' ) } →
                        </a>
                    ) }
                </li>
            ) ) }
        </ul>
    );
}
