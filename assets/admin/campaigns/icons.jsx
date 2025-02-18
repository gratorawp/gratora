// Lucide icon wrappers for campaign KPI cards and actions.

import {
    Activity,
    ArrowDown,
    ArrowUp,
    Coins,
    Heart,
    MoreHorizontal,
    Plus,
    Users,
} from 'lucide-react';

const STROKE = 1.75;

export function IconCoins( props ) {
    return <Coins size={ 18 } strokeWidth={ STROKE } { ...props } />;
}

export function IconHeart( props ) {
    return <Heart size={ 18 } strokeWidth={ STROKE } { ...props } />;
}

export function IconUsers( props ) {
    return <Users size={ 18 } strokeWidth={ STROKE } { ...props } />;
}

export function IconActivity( props ) {
    return <Activity size={ 18 } strokeWidth={ STROKE } { ...props } />;
}

export function IconPlus( props ) {
    return <Plus size={ 14 } strokeWidth={ STROKE } { ...props } />;
}

export function IconArrowUp( props ) {
    return <ArrowUp size={ 10 } strokeWidth={ 2.25 } { ...props } />;
}

export function IconArrowDown( props ) {
    return <ArrowDown size={ 10 } strokeWidth={ 2.25 } { ...props } />;
}

export function IconMore( props ) {
    return <MoreHorizontal size={ 14 } strokeWidth={ STROKE } { ...props } />;
}
