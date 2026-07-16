import { useEffect, useState, useMemo } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import Card from '../../_shared/components/Card';
import Btn from '../../_shared/components/Btn';

const CAP_GROUPS = [
    {
        label: __( 'Donors', 'dono' ),
        caps: [
            [ 'dono_view_donors',   __( 'View donors', 'dono' ) ],
            [ 'dono_edit_donors',   __( 'Edit donor records', 'dono' ) ],
            [ 'dono_export_donors', __( 'Export donor list (CSV)', 'dono' ) ],
            [ 'dono_redact_donors', __( 'Redact donors (GDPR)', 'dono' ) ],
        ],
    },
    {
        label: __( 'Donations', 'dono' ),
        caps: [
            [ 'dono_view_donations',   __( 'View donations', 'dono' ) ],
            [ 'dono_edit_donations',   __( 'Edit donations (notes)', 'dono' ) ],
            [ 'dono_refund_donations', __( 'Refund donations', 'dono' ) ],
            [ 'dono_resend_receipt',   __( 'Resend receipts', 'dono' ) ],
        ],
    },
    {
        label: __( 'Reports & setup', 'dono' ),
        caps: [
            [ 'dono_view_reports',     __( 'View dashboards & reports', 'dono' ) ],
            [ 'dono_manage_campaigns', __( 'Manage campaigns', 'dono' ) ],
            [ 'dono_manage_forms',     __( 'Manage donation forms', 'dono' ) ],
            [ 'dono_manage_settings',  __( 'Manage settings', 'dono' ) ],
        ],
    },
];

const ALL_CAPS = CAP_GROUPS.flatMap( ( g ) => g.caps.map( ( c ) => c[ 0 ] ) );

export default function RolesPanel( { s } ) {
    const [ roles, setRoles ] = useState( null );

    useEffect( () => {
        apiFetch( { path: '/dono/v1/admin/roles' } )
            .then( setRoles )
            .catch( () => setRoles( [] ) );
    }, [] );

    const mapping = useMemo( () => {
        const stored = s.value( 'mapping', {} );
        return ( stored && typeof stored === 'object' ) ? stored : {};
    }, [ s.value( 'mapping', {} ) ] );

    if ( ! roles ) return <p>{ __( 'Loading…', 'dono' ) }</p>;

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

    const setAll = ( slug, on ) => setRoleCaps( slug, on ? ALL_CAPS : [] );

    return (
        <div className="dono-panel">
            <Card edited={ s.isDirty }>
                <div className="dono-roles-table" style={ { '--dono-role-count': roles.length } }>
                    <div className="dono-roles-table__head">
                        <div className="dono-roles-table__role-cell">{ __( 'Capability', 'dono' ) }</div>
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
                                        { __( 'All', 'dono' ) }
                                    </Btn>
                                    <Btn
                                        variant="ghost"
                                        size="sm"
                                        onClick={ () => setAll( r.slug, false ) }
                                        disabled={ r.slug === 'administrator' }
                                    >
                                        { __( 'None', 'dono' ) }
                                    </Btn>
                                </div>
                            </div>
                        ) ) }
                    </div>

                    { CAP_GROUPS.map( ( group ) => (
                        <div key={ group.label } className="dono-roles-table__group">
                            <div className="dono-roles-table__group-label">{ group.label }</div>
                            { group.caps.map( ( [ cap, label ] ) => (
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
