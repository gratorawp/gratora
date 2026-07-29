
import {
    Building2,
    CheckCircle2,
    CircleDollarSign,
    CreditCard,
    Hash,
    KeyRound,
    Mail,
    MoreHorizontal,
    Palette,
    Puzzle,
    Receipt,
    Settings,
    Shield,
    Users,
} from 'lucide-react';

const STROKE = 1.75;

export function IconSetup( props ) {
    return <CheckCircle2 strokeWidth={ STROKE } { ...props } />;
}

export function IconOrganization( props ) {
    return <Building2 strokeWidth={ STROKE } { ...props } />;
}

export function IconCurrency( props ) {
    return <CircleDollarSign strokeWidth={ STROKE } { ...props } />;
}

export function IconGateways( props ) {
    return <CreditCard strokeWidth={ STROKE } { ...props } />;
}

export function IconEmail( props ) {
    return <Mail strokeWidth={ STROKE } { ...props } />;
}

export function IconReceipt( props ) {
    return <Receipt strokeWidth={ STROKE } { ...props } />;
}

export function IconNumbering( props ) {
    return <Hash strokeWidth={ STROKE } { ...props } />;
}

export function IconPrivacy( props ) {
    return <Shield strokeWidth={ STROKE } { ...props } />;
}

export function IconRoles( props ) {
    return <Users strokeWidth={ STROKE } { ...props } />;
}

export function IconBrand( props ) {
    return <Palette strokeWidth={ STROKE } { ...props } />;
}

export function IconLicense( props ) {
    return <KeyRound strokeWidth={ STROKE } { ...props } />;
}

// Fallback for an add-on tab that registers no icon of its own.
export function IconExtension( props ) {
    return <Puzzle strokeWidth={ STROKE } { ...props } />;
}

export function IconAdvanced( props ) {
    return <Settings strokeWidth={ STROKE } { ...props } />;
}

export function IconOverflow( props ) {
    return <MoreHorizontal strokeWidth={ STROKE } size={ 16 } { ...props } />;
}
