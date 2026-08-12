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
        apiFetch( { path: '/dono/v1/admin/roles' } )
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
            <div className="dono-panel">
                <Card>
                    <p style={ { color: '#b42318', margin: '0 0 12px' } }>
                        { __( 'Could not load roles.', 'dono-fundraising-platform' ) }
                    </p>
                    <Btn variant="secondary" onClick={ load }>{ __( 'Retry', 'dono-fundraising-platform' ) }</Btn>
                </Card>
            </div>
        );
    }
    if ( ! data ) return <p>{ __( 'Loading…', 'dono-fundraising-platform' ) }</p>;

    // Both come from the server: an add-on registers capabilities through the
    // dono.capabilities filter, so a list kept here could never include them.
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
        <div className="dono-panel">
            <Card edited={ s.isDirty }>
                <div className="dono-roles-table" style={ { '--dono-role-count': roles.length } }>
                    <div className="dono-roles-table__head">
                        <div className="dono-roles-table__role-cell">{ __( 'Capability', 'dono-fundraising-platform' ) }</div>
                        { roles.map( ( r ) => (
                            <div key={ r.slug } className="dono-roles-table__role">
                                <strong>{ r.name }</strong>
                                <div className="dono-roles-table__role-actions">
                                    <Btn
                                        variant="ghost"
                                        size="sm"
                                        onClick={ () => setAll( r.slug, true ) }
                                        disabled={ r.slug === 'administrator' }
                                    >
                                        { __( 'All', 'dono-fundraising-platform' ) }
                                    </Btn>
                                    <Btn
                                        variant="ghost"
                                        size="sm"
                                        onClick={ () => setAll( r.slug, false ) }
                                        disabled={ r.slug === 'administrator' }
                                    >
                                        { __( 'None', 'dono-fundraising-platform' ) }
                                    </Btn>
                                </div>
                            </div>
                        ) ) }
                    </div>

                    { capGroups.map( ( group ) => (
                        <div key={ group.label } className="dono-roles-table__group">
                            <div className="dono-roles-table__group-label">{ group.label }</div>
                            { group.caps.map( ( { cap, label } ) => (
                                <div key={ cap } className="dono-roles-table__row">
                                    <div className="dono-roles-table__cap">{ label }</div>
                                    { roles.map( ( r ) => {
                                        const has  = ( mapping[ r.slug ] || [] ).includes( cap );
                                        const lock = r.slug === 'administrator';
                                        return (
                                            <div key={ r.slug } className="dono-roles-table__cell">
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
