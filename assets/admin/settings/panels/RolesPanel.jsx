import { useEffect, useState, useMemo } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import Card from '../../_shared/components/Card';
import Btn from '../../_shared/components/Btn';

export default function RolesPanel( { s } ) {
    const [ data, setData ]           = useState( null );
    const [ loadError, setLoadError ] = useState( false );

    const load = () => {
        setLoadError( false );
        apiFetch( { path: '/giveflow/v1/admin/roles' } )
            .then( setData )
            .catch( () => setLoadError( true ) );
    };

    useEffect( () => { load(); }, [] );

    const mapping = useMemo( () => {
        const stored = s.value( 'mapping', {} );
        return ( stored && typeof stored === 'object' ) ? stored : {};
    }, [ s.value( 'mapping', {} ) ] );

    if ( loadError ) {
        return (
            <div className="giveflow-panel">
                <Card>
                    <p style={ { color: '#b42318', margin: '0 0 12px' } }>
                        { __( 'Could not load roles.', 'giveflow-fundraising-campaigns' ) }
                    </p>
                    <Btn variant="secondary" onClick={ load }>{ __( 'Retry', 'giveflow-fundraising-campaigns' ) }</Btn>
                </Card>
            </div>
        );
    }
    if ( ! data ) return <p>{ __( 'Loading…', 'giveflow-fundraising-campaigns' ) }</p>;

    // Both come from the server: an add-on registers capabilities through the
    // giveflow.capabilities filter, so a list kept here could never include them.
    const roles    = data.roles || [];
    const capGroups = data.capabilities || [];
    const allCaps  = capGroups.flatMap( ( g ) => g.caps.map( ( c ) => c.cap ) );

    // s.replace, not s.edit: deep merge would re-add cleared caps.
    const writeMapping = ( next ) => s.replace( { mapping: next } );

    const setRoleCaps = ( slug, caps ) => {
        // Explicit empty array prevents SettingsService::get from restoring seeded caps.
        writeMapping( { ...mapping, [ slug ]: caps } );
    };

    const toggle = ( slug, cap ) => {
        const has  = ( mapping[ slug ] || [] ).includes( cap );
        const next = has
            ? ( mapping[ slug ] || [] ).filter( ( c ) => c !== cap )
            : [ ...( mapping[ slug ] || [] ), cap ];
        setRoleCaps( slug, next );
    };

    const setAll = ( slug, on ) => setRoleCaps( slug, on ? allCaps : [] );

    return (
        <div className="giveflow-panel">
            <Card edited={ s.isDirty }>
                <div className="giveflow-roles-table" style={ { '--giveflow-role-count': roles.length } }>
                    <div className="giveflow-roles-table__head">
                        <div className="giveflow-roles-table__role-cell">{ __( 'Capability', 'giveflow-fundraising-campaigns' ) }</div>
                        { roles.map( ( r ) => (
                            <div key={ r.slug } className="giveflow-roles-table__role">
                                <strong>{ r.name }</strong>
                                <div className="giveflow-roles-table__role-actions">
                                    <Btn
                                        variant="ghost"
                                        size="sm"
                                        onClick={ () => setAll( r.slug, true ) }
                                        disabled={ r.slug === 'administrator' }
                                    >
                                        { __( 'All', 'giveflow-fundraising-campaigns' ) }
                                    </Btn>
                                    <Btn
                                        variant="ghost"
                                        size="sm"
                                        onClick={ () => setAll( r.slug, false ) }
                                        disabled={ r.slug === 'administrator' }
                                    >
                                        { __( 'None', 'giveflow-fundraising-campaigns' ) }
                                    </Btn>
                                </div>
                            </div>
                        ) ) }
                    </div>

                    { capGroups.map( ( group ) => (
                        <div key={ group.label } className="giveflow-roles-table__group">
                            <div className="giveflow-roles-table__group-label">{ group.label }</div>
                            { group.caps.map( ( { cap, label } ) => (
                                <div key={ cap } className="giveflow-roles-table__row">
                                    <div className="giveflow-roles-table__cap">{ label }</div>
                                    { roles.map( ( r ) => {
                                        const has  = ( mapping[ r.slug ] || [] ).includes( cap );
                                        const lock = r.slug === 'administrator';
                                        return (
                                            <div key={ r.slug } className="giveflow-roles-table__cell">
                                                <input
                                                    type="checkbox"
                                                    checked={ has || lock }
                                                    disabled={ lock }
                                                    onChange={ () => toggle( r.slug, cap ) }
                                                    aria-label={ `${ label } - ${ r.name }` }
                                                />
                                            </div>
                                        );
                                    } ) }
                                </div>
                            ) ) }
                        </div>
                    ) ) }
                </div>
            </Card>
        </div>
    );
}
