import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./resources/js', import.meta.url))
    }
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./vitest.setup.js'],
    /*
     * Git worktrees live under .claude/worktrees/, inside the project, so the
     * default discovery walked into them and ran every branch's copy of the
     * suite alongside this one: 2340 tests instead of 1050, and 768 "failures"
     * from files that were edited on another branch. Coverage measured from
     * that is meaningless, and a suite that is red for reasons nobody can act
     * on is a suite people stop reading.
     *
     * CI never saw it — worktrees only exist on a developer's machine — which
     * is exactly why it survived.
     */
    exclude: ['**/node_modules/**', '**/dist/**', '.claude/**'],
    coverage: {
      provider: 'v8',
      /*
       * `include` is what makes this number honest. Vitest 3 dropped
       * `coverage.all`, so without it the denominator is only the files a test
       * already imported — the same suite then reports 71% instead of 24%,
       * because the 120 files nobody tests are simply absent from the maths.
       */
      include: ['resources/js/**/*.{js,vue}'],
      exclude: ['resources/js/ziggy*.js'],
      reporter: ['text-summary', 'html'],
      /*
       * Pinned to what the suite actually covers today, not to an ambition.
       * This is a ratchet: raise it whenever coverage climbs, never lower it.
       * The frontend is the least-tested half of this app (120 of 161 source
       * files at 0%), so the floor is low on purpose — a threshold nobody can
       * meet is a threshold somebody deletes.
       */
      thresholds: {
        statements: 24,
        branches: 20,
        functions: 16,
        lines: 24
      }
    }
  }
})
