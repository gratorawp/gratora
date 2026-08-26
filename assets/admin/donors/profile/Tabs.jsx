import { __ } from '@wordpress/i18n';

import { tablistKeyDown } from '../../_shared/tablistKeys';
import { IconBars, IconActivity, IconHeart, IconRotate, IconFile, IconNote, IconShield } from './icons';

const TAB_DEFS = [
    { id: 'overview',  label: __( 'Overview',  'giveflow-fundraising-campaigns' ), Icon: IconBars     },
    { id: 'donations', label: __( 'Donations', 'giveflow-fundraising-campaigns' ), Icon: IconHeart    },
    { id: 'recurring', label: __( 'Recurring', 'giveflow-fundraising-campaigns' ), Icon: IconRotate   },
    { id: 'receipts',  label: __( 'Receipts',  'giveflow-fundraising-campaigns' ), Icon: IconFile     },
    { id: 'notes',     label: __( 'Notes',     'giveflow-fundraising-campaigns' ), Icon: IconNote     },
    { id: 'consent',   label: __( 'Consent',   'giveflow-fundraising-campaigns' ), Icon: IconShield   },
    { id: 'activity',  label: __( 'Activity',  'giveflow-fundraising-campaigns' ), Icon: IconActivity },
];

export default function Tabs( { active, onChange, counts = {}, dots = {} } ) {
    return (
        <div
            className="dp-tabs"
            role="tablist"
            tabIndex={ -1 }
            aria-label={ __( 'Donor sections', 'giveflow-fundraising-campaigns' ) }
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
        </div>
    );
}
