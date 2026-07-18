import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { ShieldCheck } from 'lucide-react';

import Card from '../../_shared/components/Card';
import Btn from '../../_shared/components/Btn';
import EmptyState from '../../_shared/components/EmptyState';
import ConfirmDialog from '../../_shared/components/ConfirmDialog';
import { Switch } from '../../_shared/components/Switch';

const DEFAULT_PURPOSE = {
    key:         '',
    label:       '',
    description: '',
    required:    false,
    default:     false,
    version:     1,
};

function slugify( s ) {
    return String( s || '' )
        .toLowerCase()
        .replace( /[^a-z0-9]+/g, '_' )
        .replace( /^_+|_+$/g, '' )
        .slice( 0, 60 );
}

// Length-based keys (`purpose_${list.length + 1}`) collide after an
// add/delete/add: two purposes end up sharing `purpose_2`, and the key is the
// stable identifier for the append-only consent audit log. Pick the lowest
// purpose_N not already taken.
function uniquePurposeKey( list ) {
    const taken = new Set( ( list || [] ).map( ( p ) => p.key ) );
    let i = list.length + 1;
    while ( taken.has( `purpose_${ i }` ) ) i++;
    return `purpose_${ i }`;
}

export default function ConsentsPanel( { s } ) {
    const [ confirm, setConfirm ] = useState( null );

    const list = Array.isArray( s.value( 'purposes', [] ) ) ? s.value( 'purposes', [] ) : [];

    const setList = ( next ) => s.edit( { purposes: next } );

    const update = ( i, patch ) => {
        const next = list.map( ( p, idx ) => idx === i ? { ...p, ...patch } : p );
        setList( next );
    };

    const remove = ( i ) => {
        setConfirm( {
            title:        __( 'Delete consent purpose', 'dono' ),
            message:      __( 'Delete this consent purpose? Donor consent history stays in the audit log.', 'dono' ),
            confirmLabel: __( 'Delete', 'dono' ),
            destructive:  true,
            onConfirm: async () => {
                setList( list.filter( ( _, idx ) => idx !== i ) );
            },
        } );
    };

    const add = () => setList( [
        ...list,
        { ...DEFAULT_PURPOSE, key: uniquePurposeKey( list ), version: 1 },
    ] );

    return (
        <>
        <Card
            title={ __( 'Consent purposes', 'dono' ) }
            sub={ __( 'What donors can opt into. Each toggle is logged in an append-only audit trail. Bump the version when you change a description so existing donors are prompted to re-consent.', 'dono' ) }
            edited={ s.isDirty }
        >
            <div className="dono-consents">
                { list.length === 0 && (
                    <EmptyState
                        compact
                        icon={ <ShieldCheck size={ 22 } strokeWidth={ 1.75 } /> }
                        title={ __( 'No consent purposes yet', 'dono' ) }
                        body={ __( 'Add the first purpose below. Each toggle becomes an opt-in on every donation form.', 'dono' ) }
                        action={
                            <Btn variant="secondary" onClick={ add }>
                                { __( 'Add a purpose', 'dono' ) }
                            </Btn>
                        }
                    />
                ) }

                { list.map( ( p, i ) => (
                    <div key={ p.key } className="dono-consent-card">
                        <header className="dono-consent-card__head">
                            <input
                                className="dono-input dono-consent-card__label"
                                type="text"
                                value={ p.label }
                                placeholder={ __( 'Purpose name', 'dono' ) }
                                onChange={ ( e ) => update( i, {
                                    label: e.target.value,
                                    key:   p.key || slugify( e.target.value ),
                                } ) }
                            />
                            <button
                                type="button"
                                className="dono-consent-card__delete"
                                onClick={ () => remove( i ) }
                                aria-label={ __( 'Delete purpose', 'dono' ) }
                            >
                                { __( 'Delete', 'dono' ) }
                            </button>
                        </header>

                        <textarea
                            className="dono-textarea dono-consent-card__desc"
                            rows={ 3 }
                            value={ p.description }
                            placeholder={ __( 'Enter a donor-facing description', 'dono' ) }
                            onChange={ ( e ) => update( i, { description: e.target.value } ) }
                        />

                        <footer className="dono-consent-card__foot">
                            <label className="dono-consent-card__meta-field">
                                <span>{ __( 'Key', 'dono' ) }</span>
                                <input
                                    className="dono-input dono-input--mono"
                                    type="text"
                                    value={ p.key }
                                    onChange={ ( e ) => update( i, { key: slugify( e.target.value ) } ) }
                                    pattern="^[a-z0-9_]+$"
                                />
                            </label>
                            <label className="dono-consent-card__meta-field dono-consent-card__meta-field--narrow">
                                <span>{ __( 'Version', 'dono' ) }</span>
                                <input
                                    className="dono-input"
                                    type="number"
                                    min={ 1 }
                                    value={ p.version || 1 }
                                    onChange={ ( e ) => update( i, { version: parseInt( e.target.value, 10 ) || 1 } ) }
                                />
                            </label>

                            <div className="dono-consent-card__toggles">
                                <SwitchChip
                                    label={ __( 'Required to donate', 'dono' ) }
                                    checked={ !! p.required }
                                    onChange={ ( v ) => update( i, { required: v } ) }
                                />
                                <SwitchChip
                                    label={ __( 'Pre-selected', 'dono' ) }
                                    checked={ !! p.default }
                                    onChange={ ( v ) => update( i, { default: v } ) }
                                />
                            </div>
                        </footer>
                    </div>
                ) ) }

                <Btn variant="ghost" onClick={ add } className="dono-consents__add">
                    + { __( 'Add consent purpose', 'dono' ) }
                </Btn>
            </div>
        </Card>
        <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </>
    );
}

function SwitchChip( { label, checked, onChange } ) {
    return (
        <label className="dono-consent-card__chip">
            <Switch checked={ !! checked } onChange={ onChange } />
            <span className="dono-consent-card__chip-label">{ label }</span>
        </label>
    );
}
