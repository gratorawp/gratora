/**
 * Presentational KPI card strip above admin list tables; each list passes its own
 * items ({ label, value, sub? }) so card markup + loading treatment stay identical.
 */
export default function KpiStrip( { items, loading } ) {
    return (
        <div className="gratora-kpi-strip" aria-busy={ loading } style={ { opacity: loading ? 0.5 : 1 } }>
            { items.map( ( it, i ) => (
                <div key={ i } className="gratora-kpi-strip__card">
                    <div className="gratora-kpi-strip__label">{ it.label }</div>
                    <div className="gratora-kpi-strip__value">{ it.value }</div>
                    { it.sub && <div className="gratora-kpi-strip__sub">{ it.sub }</div> }
                </div>
            ) ) }
        </div>
    );
}
