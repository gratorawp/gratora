/**
 * Small gradient badge with a single letter, used in card heads for gateway
 * branding (Stripe, Offline, ...).
 */
export default function BrandMark( { letter, variant } ) {
    const cls = `giveflow-brand-mark${ variant ? ` giveflow-brand-mark--${ variant }` : '' }`;
    return <span className={ cls } aria-hidden="true">{ letter }</span>;
}
