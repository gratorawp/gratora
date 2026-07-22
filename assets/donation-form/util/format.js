// One money source: @dono/ui. The public form has no window.dono, so its
// number format arrives through the explicit override channel - bootstrap
// calls setActiveNumberFormat(config.numberFormat) once before render and
// every call site stays zero-arg.
export {
    setActiveNumberFormat,
    getActiveNumberFormat,
    formatAmount,
    formatAmountCompact,
    groupDigits,
    parseAmount,
} from '@dono/ui/utils/format';
