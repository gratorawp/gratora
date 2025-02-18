// Lucide icons for DonorProfile + tabs.

import {
    Activity,
    AlertTriangle,
    BarChart3,
    Calendar,
    Check,
    Clock,
    Coins,
    Copy,
    Download,
    File,
    Heart,
    Info,
    Link as LinkIcon,
    Mail,
    MapPin,
    MoreVertical,
    Phone,
    RefreshCw,
    Reply,
    RotateCcw,
    ShieldCheck,
    StickyNote,
    Trash2,
} from 'lucide-react';

const STROKE = 1.75;

export const IconMail     = ( p ) => <Mail          strokeWidth={ STROKE } { ...p } />;
export const IconPhone    = ( p ) => <Phone         strokeWidth={ STROKE } { ...p } />;
export const IconMapPin   = ( p ) => <MapPin        strokeWidth={ STROKE } { ...p } />;
export const IconFile     = ( p ) => <File          strokeWidth={ STROKE } { ...p } />;
export const IconCalendar = ( p ) => <Calendar      strokeWidth={ STROKE } { ...p } />;
export const IconCopy     = ( p ) => <Copy          strokeWidth={ STROKE } { ...p } />;
export const IconReply    = ( p ) => <Reply         strokeWidth={ STROKE } { ...p } />;
export const IconLink     = ( p ) => <LinkIcon      strokeWidth={ STROKE } { ...p } />;
export const IconKebab    = ( p ) => <MoreVertical  strokeWidth={ STROKE } { ...p } />;
export const IconAlert    = ( p ) => <AlertTriangle strokeWidth={ STROKE } { ...p } />;
export const IconInfo     = ( p ) => <Info          strokeWidth={ STROKE } { ...p } />;
export const IconCheck    = ( p ) => <Check         strokeWidth={ 2.4 }    { ...p } />;
export const IconRotate   = ( p ) => <RefreshCw     strokeWidth={ STROKE } { ...p } />;
export const IconCoin     = ( p ) => <Coins         strokeWidth={ STROKE } { ...p } />;
export const IconHeart    = ( p ) => <Heart         strokeWidth={ STROKE } { ...p } />;
export const IconActivity = ( p ) => <Activity      strokeWidth={ STROKE } { ...p } />;
export const IconShield   = ( p ) => <ShieldCheck   strokeWidth={ STROKE } { ...p } />;
export const IconNote     = ( p ) => <StickyNote    strokeWidth={ STROKE } { ...p } />;
export const IconTrash    = ( p ) => <Trash2        strokeWidth={ STROKE } { ...p } />;
export const IconDownload = ( p ) => <Download      strokeWidth={ STROKE } { ...p } />;
export const IconClock    = ( p ) => <Clock         strokeWidth={ STROKE } { ...p } />;
export const IconRefund   = ( p ) => <RotateCcw     strokeWidth={ STROKE } { ...p } />;
export const IconBars     = ( p ) => <BarChart3     strokeWidth={ STROKE } { ...p } />;
