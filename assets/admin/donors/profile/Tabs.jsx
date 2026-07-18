import { __ } from '@wordpress/i18n';

import { tablistKeyDown } from '../../_shared/tablistKeys';
import { IconBars, IconHeart, IconRotate, IconFile, IconNote, IconShield } from './icons';

const TAB_DEFS = [
    { id: 'activity',  label: __( 'Activity',  'dono' ), Icon: IconBars   },
    { id: 'donations', label: __( 'Donations', 'dono' ), Icon: IconHeart  },
    { id: 'recurring', label: __( 'Recurring', 'dono' ), Icon: IconRotate },
    { id: 'receipts',  label: __( 'Receipts',  'dono' ), Icon: IconFile   },
    { id: 'notes',     label: __( 'Notes',     'dono' ), Icon: IconNote   },
    { id: 'consent',   label: __( 'Consent',   'dono' ), Icon: IconShield },
];

export default function Tabs( { active, onChange, counts = {}, dots = {} } ) {
    return (
        <nav
            className="dp-tabs"
            role="tablist"
            aria-label={ __( 'Donor sections', 'dono' ) }
            onKeyDown={ ( e ) => tablistKeyDown( e, TAB_DEFS.map( ( d ) => d.id ), active, onChange ) }
        >
            { TAB_DEFS.map( ( t ) => (
                <a
                    key={ t.id }
                    href={ `#${ t.id }` }
                    role="tab"
                    aria-selected={ active === t.id }
                    tabIndex={ active === t.id ? 0 : -1 }
                    className={ active === t.id ? 'is-active' : '' }
                    onClick={ ( e ) => { e.preventDefault(); onChange( t.id ); } }
                >
                    <t.Icon className="dp-tab__icon" width="15" height="15" />
                    { t.label }
                    { counts[ t.id ] !== undefined && counts[ t.id ] !== null && (
                        <span className="dp-tab__count">{ counts[ t.id ] }</span>
                    ) }
                    { dots[ t.id ] && (
                        <span className={ `dp-tab__dot ${ dots[ t.id ] }` } />
                    ) }
                </a>
            ) ) }
        </nav>
    );
}
