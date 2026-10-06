import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { ArrowRight, Circle, CircleCheck, ExternalLink } from 'lucide-react';

import Btn from '../_shared/components/Btn';
import { listHref } from '../_shared/format';
import { firstRunSteps, primaryStep } from './firstRunSteps';

const DEMO_URL = 'https://gratora.net/demo/?utm_source=plugin&utm_medium=dashboard';

const settingsHref = ( tab ) => `${ window.location.pathname }?page=gratora-settings#${ tab }`;

const NewTab = () => (
    <span className="screen-reader-text">{ __( '(opens in a new tab)', 'gratora-donation-platform' ) }</span>
);

/**
 * What is left between a new site and its first real donation. The server
 * decides when it shows; this draws the facts it was given and presses the
 * server's own buttons.
 */
export default function FirstRunCard( { facts, onChanged, onModeSwitched, onHidden } ) {
    const [ busy, setBusy ]   = useState( null );
    const [ error, setError ] = useState( null );

    const steps   = firstRunSteps( facts, { campaigns: listHref(), payments: settingsHref( 'gateways' ) } );
    const primary = primaryStep( steps );
    const done    = steps.filter( ( step ) => step.done ).length;

    const press = async ( kind ) => {
        if ( busy ) return;
        setBusy( kind );
        setError( null );
        try {
            if ( kind === 'create' ) {
                await apiFetch( { path: '/gratora/v1/admin/onboarding/starter-campaign', method: 'POST' } );
                onChanged();
            } else {
                const testMode = kind === 'test-on';
                await apiFetch( { path: '/gratora/v1/admin/settings/gateways', method: 'PUT', data: { test_mode: testMode } } );
                onModeSwitched( testMode );
            }
        } catch ( err ) {
            setError( err?.message || __( 'That did not work. Please try again.', 'gratora-donation-platform' ) );
        } finally {
            setBusy( null );
        }
    };

    const hide = () => {
        apiFetch( { path: '/gratora/v1/admin/me/first-run', method: 'POST' } ).catch( () => {} );
        onHidden();
    };

    return (
        <section className="gratora-firstrun" aria-labelledby="gratora-firstrun-title">
            <div className="gratora-firstrun__head">
                <div>
                    <h2 id="gratora-firstrun-title" className="gratora-firstrun__title">
                        { __( 'Take your first donation', 'gratora-donation-platform' ) }
                    </h2>
                    <p className="gratora-firstrun__sub">
                        { __( 'Four steps from a new install to a real donation.', 'gratora-donation-platform' ) }
                    </p>
                </div>
                <div className="gratora-firstrun__progress">
                    <span>
                        { sprintf(
                            /* translators: 1: steps done. 2: steps in all. */
                            __( '%1$d of %2$d done', 'gratora-donation-platform' ),
                            done,
                            steps.length
                        ) }
                    </span>
                    <span className="gratora-firstrun__bar" aria-hidden="true">
                        <i style={ { width: `${ ( done / steps.length ) * 100 }%` } } />
                    </span>
                    <button type="button" className="gratora-firstrun__hide" onClick={ hide }>
                        { __( 'Hide', 'gratora-donation-platform' ) }
                    </button>
                </div>
            </div>

            { error && <p className="gratora-firstrun__error" role="alert">{ error }</p> }

            <ol className="gratora-firstrun__steps">
                { steps.map( ( step, index ) => (
                    <Step
                        key={ step.key }
                        step={ step }
                        number={ index + 1 }
                        isPrimary={ step.key === primary }
                        busy={ busy }
                        onPress={ press }
                    />
                ) ) }
            </ol>

            <div className="gratora-firstrun__foot">
                <span>
                    { __( 'Want to see it full first?', 'gratora-donation-platform' ) }
                    { ' ' }
                    <a href={ DEMO_URL } target="_blank" rel="noreferrer">
                        { __( 'Open the demo, with a year of sample data', 'gratora-donation-platform' ) }
                        <ExternalLink size={ 13 } strokeWidth={ 2 } aria-hidden="true" />
                        <NewTab />
                    </a>
                </span>
                <a href={ settingsHref( 'setup' ) }>
                    { __( 'See the full setup check', 'gratora-donation-platform' ) }
                </a>
            </div>
        </section>
    );
}

function Step( { step, number, isPrimary, busy, onPress } ) {
    const textId = `gratora-firstrun-${ step.key }`;
    /* translators: %d: the step's number, 1 to 4. */
    const numbered = sprintf( __( 'Step %d', 'gratora-donation-platform' ), number );
    const classes = [
        'gratora-firstrun__step',
        step.done && 'is-done',
        isPrimary && 'is-next',
    ].filter( Boolean ).join( ' ' );

    return (
        <li className={ classes }>
            <span className="gratora-firstrun__state">
                { step.done
                    ? <CircleCheck size={ 16 } strokeWidth={ 2.25 } aria-hidden="true" />
                    : <Circle size={ 16 } strokeWidth={ 2 } aria-hidden="true" /> }
                { step.done ? __( 'Done', 'gratora-donation-platform' ) : numbered }
            </span>
            <h3 className="gratora-firstrun__name">{ step.title }</h3>
            <p id={ textId } className="gratora-firstrun__text">{ step.text }</p>
            { step.action && (
                <div className="gratora-firstrun__act">
                    <Action action={ step.action } isPrimary={ isPrimary } busy={ busy } onPress={ onPress } describedBy={ textId } />
                </div>
            ) }
        </li>
    );
}

function Action( { action, isPrimary, busy, onPress, describedBy } ) {
    if ( action.kind !== 'link' ) {
        return (
            <Btn
                variant={ isPrimary ? 'primary' : undefined }
                size="sm"
                disabled={ action.disabled || !! busy }
                isBusy={ busy === action.kind }
                onClick={ () => onPress( action.kind ) }
                aria-describedby={ describedBy }
            >
                { action.label }
            </Btn>
        );
    }

    const opens = action.newTab ? { target: '_blank', rel: 'noreferrer' } : {};

    if ( isPrimary ) {
        return (
            <Btn variant="primary" size="sm" href={ action.href } { ...opens }>
                { action.label }
                { action.newTab && <NewTab /> }
            </Btn>
        );
    }

    return (
        <a className="gratora-firstrun__link" href={ action.href } { ...opens }>
            { action.label }
            <ArrowRight size={ 14 } strokeWidth={ 2 } aria-hidden="true" />
            { action.newTab && <NewTab /> }
        </a>
    );
}
