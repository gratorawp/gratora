// Lucide icons for the donation detail view.

import {
    AlertTriangle,
    Check,
    Copy,
    Download,
    ExternalLink,
    Mail,
    MoreVertical,
    Receipt,
    RotateCcw,
    StickyNote,
    Trash2,
} from 'lucide-react';

const STROKE = 1.75;

export const IconMail     = ( p ) => <Mail          strokeWidth={ STROKE } { ...p } />;
export const IconReceipt  = ( p ) => <Receipt       strokeWidth={ STROKE } { ...p } />;
export const IconRefund   = ( p ) => <RotateCcw     strokeWidth={ STROKE } { ...p } />;
export const IconDownload = ( p ) => <Download      strokeWidth={ STROKE } { ...p } />;
export const IconExternal = ( p ) => <ExternalLink  strokeWidth={ STROKE } { ...p } />;
export const IconNote     = ( p ) => <StickyNote    strokeWidth={ STROKE } { ...p } />;
export const IconCopy     = ( p ) => <Copy          strokeWidth={ STROKE } { ...p } />;
export const IconKebab    = ( p ) => <MoreVertical  strokeWidth={ STROKE } { ...p } />;
export const IconAlert    = ( p ) => <AlertTriangle strokeWidth={ STROKE } { ...p } />;
export const IconTrash    = ( p ) => <Trash2        strokeWidth={ STROKE } { ...p } />;
export const IconCheck    = ( p ) => <Check         strokeWidth={ 2.4 }    { ...p } />;
