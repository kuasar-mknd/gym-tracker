import Dexie from 'dexie'

export const db = new Dexie('GymTrackerDB')

// Define the database schema
// version(1) is the initial schema
db.version(1).stores({
    workouts: '++id, user_id, name, started_at, ended_at',
    exercises: '++id, name, category, type',
    sync_queue: '++id, action, url, payload, timestamp, status', // status: pending, processing, failed
})

// Helper class for database operations
class DatabaseService {
    /**
     * Clear all data from the database
     */
    async clearAll() {
        await Promise.all([db.workouts.clear(), db.exercises.clear(), db.sync_queue.clear()])
    }

    /**
     * Save/Update a workout in the local database
     */
    async saveWorkout(workout) {
        return await db.workouts.put(workout)
    }

    /**
     * Get all workouts from the local database
     */
    async getWorkouts() {
        return await db.workouts.toArray()
    }

    /**
     * Save exercises to the local database
     */
    async saveExercises(exercises) {
        return await db.exercises.bulkPut(exercises)
    }

    /**
     * Get all exercises from the local database
     */
    async getExercises() {
        return await db.exercises.toArray()
    }

    /**
     * Add an item to the sync queue
     */
    async addToSyncQueue(action, url, payload) {
        return await db.sync_queue.add({
            action, // 'POST', 'PATCH', 'DELETE'
            url,
            payload,
            timestamp: Date.now(),
            status: 'pending',
        })
    }

    /**
     * Get pending items from the sync queue
     */
    async getPendingSyncItems() {
        return await db.sync_queue.where('status').equals('pending').toArray()
    }

    /**
     * Update sync item status
     */
    async updateSyncStatus(id, status) {
        return await db.sync_queue.update(id, { status })
    }

    /**
     * Remove a sync item
     */
    async removeSyncItem(id) {
        return await db.sync_queue.delete(id)
    }
}

export const databaseService = new DatabaseService()
