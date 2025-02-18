import { useBlockProps, InspectorControls, RichText } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl, Button } from '@wordpress/components';
import Segmented from '../../../_shared/components/Segmented';
import { __ } from '@wordpress/i18n';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import { BlockIcons } from '../_shared/block-icons';

const NAME = 'dono/consent';

const DEFAULT_PURPOSES = [
    { id: 'email_updates', label: 'Email me about future campaigns', description: '', requiredByLaw: false },
];

function slugify( s ) {
    return String( s || '' )
        .toLowerCase()
        .replace( /[^a-z0-9]+/g, '_' )
        .replace( /^_+|_+$/g, '' );
}

function normalizePurposes( purposes ) {
    if ( ! Array.isArray( purposes ) || purposes.length === 0 ) return DEFAULT_PURPOSES;
    return purposes.map( ( p, i ) => ( {
        id:            String( p?.id || '' ).trim() || `purpose_${ i + 1 }`,
        label:         String( p?.label || '' ),
        description:   String( p?.description || '' ),
        requiredByLaw: !! p?.requiredByLaw,
    } ) );
}

function Edit( { attributes, setAttributes } ) {
    const {
        label        = '',
        helpText     = '',
        defaultState = 'opt-in',
        condition    = DEFAULT_CONDITION,
    } = attributes;

    const purposes = normalizePurposes( attributes.purposes );

    const blockProps = useBlockProps( { className: 'dono-block-preview dono-block-preview--consent' } );

    const updatePurpose = ( i, patch ) => {
        const next = purposes.map( ( p, idx ) => idx === i ? { ...p, ...patch } : p );
        setAttributes( { purposes: next } );
    };

    const addPurpose = () => {
        const used = new Set( purposes.map( ( p ) => p.id ) );
        let n  = purposes.length + 1;
        let id = `purpose_${ n }`;
        while ( used.has( id ) ) { n++; id = `purpose_${ n }`; }
        setAttributes( {
            purposes: [ ...purposes, { id, label: '', description: '', requiredByLaw: false } ],
        } );
    };

    const removePurpose = ( i ) => setAttributes( {
        purposes: purposes.filter( ( _, idx ) => idx !== i ),
    } );

    const initialChecked = ( p ) => p.requiredByLaw || defaultState === 'opt-out';

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Consent', 'dono' ) } initialOpen>
                    <TextControl
                        label={ __( 'Heading', 'dono' ) }
                        value={ label }
                        onChange={ ( v ) => setAttributes( { label: v } ) }
                        placeholder={ __( 'How can we stay in touch?', 'dono' ) }
                        help={ __( 'Click the heading or any purpose label to edit inline.', 'dono' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Help text', 'dono' ) }
                        value={ helpText }
                        onChange={ ( v ) => setAttributes( { helpText: v } ) }
                        placeholder={ __( 'Optional explanation shown below the heading.', 'dono' ) }
                        __nextHasNoMarginBottom
                    />
                    <Segmented
                        label={ __( 'Default state', 'dono' ) }
                        value={ defaultState }
                        onChange={ ( v ) => setAttributes( { defaultState: v } ) }
                        options={ [
                            { value: 'opt-in',  label: __( 'Opt-in',  'dono' ) },
                            { value: 'opt-out', label: __( 'Opt-out', 'dono' ) },
                        ] }
                        help={ __( 'Required-by-law purposes are always on regardless.', 'dono' ) }
                    />
                    { purposes.map( ( p, i ) => (
                        <div key={ i } style={ { borderBottom: '1px solid #f0f0f1', paddingBottom: 12, marginBottom: 12 } }>
                            <TextControl
                                label={ __( 'ID', 'dono' ) }
                                value={ p.id }
                                onChange={ ( v ) => updatePurpose( i, { id: slugify( v ) || `purpose_${ i + 1 }` } ) }
                                help={ __( 'Stored as the consent key. Lowercase, snake_case.', 'dono' ) }
                                __nextHasNoMarginBottom
                            />
                            <TextControl
                                label={ __( 'Label', 'dono' ) }
                                value={ p.label }
                                onChange={ ( v ) => updatePurpose( i, { label: v } ) }
                                __nextHasNoMarginBottom
                            />
                            <TextControl
                                label={ __( 'Description', 'dono' ) }
                                value={ p.description }
                                onChange={ ( v ) => updatePurpose( i, { description: v } ) }
                                __nextHasNoMarginBottom
                            />
                            <ToggleControl
                                label={ __( 'Required by law', 'dono' ) }
                                checked={ p.requiredByLaw }
                                onChange={ ( v ) => updatePurpose( i, { requiredByLaw: v } ) }
                                help={ __( 'Forces this purpose on and shows a Required pill.', 'dono' ) }
                                __nextHasNoMarginBottom
                            />
                            <Button
                                variant="tertiary"
                                isDestructive
                                onClick={ () => removePurpose( i ) }
                                style={ { marginTop: 4 } }
                                disabled={ purposes.length <= 1 }
                            >
                                { __( 'Remove purpose', 'dono' ) }
                            </Button>
                        </div>
                    ) ) }
                    <Button variant="secondary" onClick={ addPurpose }>
                        { __( 'Add purpose', 'dono' ) }
                    </Button>
                </PanelBody>
                <ConditionPanel
                    condition={ condition }
                    onChange={ ( c ) => setAttributes( { condition: c } ) }
                />
            </InspectorControls>
            <div { ...blockProps }>
                <RichText
                    tagName="div"
                    className="dono-block-preview__title"
                    value={ label }
                    onChange={ ( v ) => setAttributes( { label: v } ) }
                    placeholder={ __( 'How can we stay in touch?', 'dono' ) }
                    allowedFormats={ [] }
                />
                <RichText
                    tagName="p"
                    value={ helpText }
                    onChange={ ( v ) => setAttributes( { helpText: v } ) }
                    placeholder={ __( 'Optional explanation shown below the heading.', 'dono' ) }
                    allowedFormats={ [] }
                    style={ { fontSize: 12, color: '#6b7280', margin: '0 0 10px', lineHeight: 1.4 } }
                />
                <div style={ { display: 'flex', flexDirection: 'column', gap: 8 } }>
                    { purposes.map( ( p, i ) => {
                        const checked = initialChecked( p );
                        return (
                            <div
                                key={ i }
                                style={ {
                                    display:     'flex',
                                    alignItems:  'flex-start',
                                    gap:         10,
                                    padding:     '8px 10px',
                                    background:  '#fafbfc',
                                    border:      '1px solid #e5e7eb',
                                    borderRadius: 'var(--dono-radius-sm, 6px)',
                                } }
                            >
                                <span
                                    style={ {
                                        width:        16,
                                        height:       16,
                                        borderRadius: 3,
                                        border:       '1px solid #888',
                                        background:   checked ? 'var(--dono-accent, #1e8a4e)' : '#fff',
                                        flexShrink:   0,
                                        marginTop:    2,
                                    } }
                                />
                                <div style={ { flex: 1, minWidth: 0, display: 'flex', flexDirection: 'column', gap: 2 } }>
                                    <div style={ { display: 'flex', alignItems: 'center', gap: 6, flexWrap: 'wrap' } }>
                                        <RichText
                                            tagName="span"
                                            value={ p.label }
                                            onChange={ ( v ) => updatePurpose( i, { label: v } ) }
                                            placeholder={ __( 'Purpose label', 'dono' ) }
                                            allowedFormats={ [] }
                                            style={ { fontSize: 13, fontWeight: 500, color: '#111827' } }
                                        />
                                        { p.requiredByLaw && (
                                            <span
                                                style={ {
                                                    fontSize:    9,
                                                    fontWeight:  600,
                                                    textTransform: 'uppercase',
                                                    letterSpacing: '.04em',
                                                    padding:     '1px 6px',
                                                    background:  '#fef3c7',
                                                    color:       '#92400e',
                                                    borderRadius: 999,
                                                } }
                                            >
                                                { __( 'Required', 'dono' ) }
                                            </span>
                                        ) }
                                    </div>
                                    { ( p.description !== '' || p.requiredByLaw ) && (
                                        <RichText
                                            tagName="span"
                                            value={ p.description }
                                            onChange={ ( v ) => updatePurpose( i, { description: v } ) }
                                            placeholder={ __( 'Optional description', 'dono' ) }
                                            allowedFormats={ [] }
                                            style={ { fontSize: 11, color: '#6b7280', lineHeight: 1.3 } }
                                        />
                                    ) }
                                </div>
                            </div>
                        );
                    } ) }
                </div>
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion:  3,
        title:       __( 'Consent', 'dono' ),
        description: __( 'GDPR-class consent toggles. Donors opt in to each purpose individually.', 'dono' ),
        category:    'dono-extras',
        icon:        BlockIcons[ 'consent' ],
        supports: { html: false, anchor: false, inserter: true, multiple: false },
        attributes: {
            label:        { type: 'string', default: '' },
            helpText:     { type: 'string', default: '' },
            purposes:     { type: 'array',  default: [
                { id: 'email_updates', label: 'Email me about future campaigns', description: '', requiredByLaw: false },
            ] },
            defaultState: { type: 'string', default: 'opt-in' },
            condition:    { type: 'object', default: DEFAULT_CONDITION },
        },
        edit: Edit,
        save: () => null,
    } );
}
