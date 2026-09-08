import { useState } from '@wordpress/element';

let seq = 0;

/**
 * Wrap single controls in a label. Use htmlFor for nested inputs or group for multiple controls
 * to avoid nested labels.
 */
export default function Field( { label, help, footer, htmlFor, group, children } ) {
    const [ id ] = useState( () => `fundkit-field-${ ++seq }` );

    const wrapInLabel = !! label && ! htmlFor && ! group;
    const Wrapper     = wrapInLabel ? 'label' : 'div';
    const Caption     = htmlFor ? 'label' : 'span';

    const wrapperProps = group && label
        ? { role: 'group', 'aria-labelledby': id }
        : {};

    return (
        <Wrapper className="fundkit-field" { ...wrapperProps }>
            { label && (
                <Caption
                    className="fundkit-field__label"
                    htmlFor={ htmlFor }
                    id={ group ? id : undefined }
                >
                    { label }
                </Caption>
            ) }
            { help && <div className="fundkit-field__help">{ help }</div> }
            { children }
            { footer && <div className="fundkit-field__footer">{ footer }</div> }
        </Wrapper>
    );
}
