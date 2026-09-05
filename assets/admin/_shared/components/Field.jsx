import { useState } from '@wordpress/element';

let seq = 0;

/**
 * Form field wrapper: label, optional help text, optional footer.
 *
 * Local rather than the package's, which renders the label as a plain div: a
 * control inside it had no accessible name at all, so a screen reader read
 * every field in the drawers as an unlabelled box.
 *
 * A single control is wrapped in the label, which associates the two without
 * needing an id on either. Pass htmlFor for a control that renders its own
 * input, or group for several controls that share one caption, where an outer
 * label would nest inside theirs and swallow their clicks.
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
