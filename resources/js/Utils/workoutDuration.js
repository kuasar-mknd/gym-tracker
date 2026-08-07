/**
 * How long a session lasted, in minutes, or null while it is still running.
 *
 * Three charts each computed this inline and each returned `0` for a session
 * with no ended_at. Zero is a measurement — it says the session lasted no time
 * at all — so the moment you started a workout, the last point of every
 * duration curve dropped to the floor, and an abandoned session left that point
 * there for good. The list directly underneath showed "-- min" for the same
 * session: the page contradicted itself.
 *
 * null is what Chart.js skips, which is the honest rendering of "not measured
 * yet".
 *
 * All three also began with a `duration_minutes` fallback. No such attribute
 * exists on a workout — neither the table nor the model has it — so that branch
 * never ran.
 *
 * @param {{started_at?: string, ended_at?: string}} workout
 * @returns {number|null} Minutes elapsed, or null if the session has not ended.
 */
export function workoutDurationMinutes(workout) {
    if (!workout?.started_at || !workout?.ended_at) {
        return null
    }

    const elapsed = new Date(workout.ended_at) - new Date(workout.started_at)

    return Number.isFinite(elapsed) ? Math.round(elapsed / 60000) : null
}
