import { statusMeta } from '../statuses';

/**
 * Single status-pill renderer for the whole admin: status -> .gratora-pill--{variant}
 * + default label. Pass `label` to override (e.g. "Published" instead of "Active").
 */
export default function StatusBadge( { status, label, variant } ) {
    const s = statusMeta( status );

    return (
        <span className={ `gratora-pill gratora-pill--${ variant || s.variant }` }>
            { label || s.label }
        </span>
    );
}
