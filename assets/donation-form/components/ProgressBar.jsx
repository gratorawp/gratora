/** @jsxImportSource preact */

export default function ProgressBar( { current, total, labels = [] } ) {
    if ( total <= 1 ) return null;
    return (
        <div
            class="dono-form__progress"
            role="progressbar"
            aria-valuemin="0"
            aria-valuemax={ total }
            aria-valuenow={ current + 1 }
        >
            { Array.from( { length: total }, ( _, i ) => {
                const label = labels[ i ] || '';
                const state = i < current
                    ? 'is-done'
                    : i === current
                        ? 'is-current'
                        : '';
                return (
                    <span
                        key={ i }
                        class={ `dono-form__progress-dot ${ state }`.trim() }
                        aria-label={ label }
                    />
                );
            } ) }
        </div>
    );
}
