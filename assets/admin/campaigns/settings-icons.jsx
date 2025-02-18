// Lucide icon wrappers for the Campaign Settings sub-tabs.

import {
    Globe,
    LayoutTemplate,
    Palette,
    Settings,
    Target,
} from 'lucide-react';

const STROKE = 1.75;

export function IconGeneral( props ) {
    return <Globe strokeWidth={ STROKE } { ...props } />;
}

export function IconGoal( props ) {
    return <Target strokeWidth={ STROKE } { ...props } />;
}

export function IconAppearance( props ) {
    return <Palette strokeWidth={ STROKE } { ...props } />;
}

export function IconDefaults( props ) {
    return <LayoutTemplate strokeWidth={ STROKE } { ...props } />;
}

export function IconAdvanced( props ) {
    return <Settings strokeWidth={ STROKE } { ...props } />;
}
