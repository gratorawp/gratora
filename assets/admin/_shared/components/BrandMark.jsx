/**
 * Small gradient badge with a single letter, used in card heads for gateway
 * branding (Stripe, Offline, ...).
 */
export default function BrandMark( { letter, variant } ) {
    const cls = `dono-brand-mark${ variant ? ` dono-brand-mark--${ variant }` : '' }`;
    return <span className={ cls } aria-hidden="true">{ letter }</span>;
}
