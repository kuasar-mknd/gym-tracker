import js from '@eslint/js'
import pluginVue from 'eslint-plugin-vue'
import globals from 'globals'

/**
 * The project had no JavaScript linter. `npm run lint:js` ran Prettier, which is
 * a formatter — it has no notion of an unused import, an unreachable branch or
 * an undefined reference, so none of that was ever checked. Five dead imports
 * had accumulated across the frontend before anything noticed.
 */
export default [
    {
        ignores: ['public/**', 'vendor/**', 'node_modules/**', 'storage/**', 'bootstrap/**', 'resources/js/ziggy.js'],
    },
    js.configs.recommended,
    ...pluginVue.configs['flat/recommended'],
    {
        files: ['resources/js/**/*.{js,vue}'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            globals: {
                ...globals.browser,
                // Ziggy's helper and the axios instance app.js puts on window.
                route: 'readonly',
                axios: 'readonly',
                Ziggy: 'readonly',
            },
        },
        rules: {
            // Inertia page components are named by their route, so Index.vue,
            // Show.vue and Edit.vue are correct and unavoidable here.
            'vue/multi-word-component-names': 'off',

            // Formatting belongs to Prettier; leaving these on means the two
            // tools disagree about the same lines.
            'vue/max-attributes-per-line': 'off',
            'vue/singleline-html-element-content-newline': 'off',
            'vue/html-self-closing': 'off',
            'vue/html-indent': 'off',
            'vue/html-closing-bracket-newline': 'off',
            'vue/attributes-order': 'off',
            'vue/first-attribute-linebreak': 'off',
            'vue/multiline-html-element-content-newline': 'off',

            // Off deliberately, and not for convenience. Inertia's deferred
            // props use `undefined` to mean "the page did not supply this", and
            // the stats components switch on exactly that:
            //   :data="deferredData ? 'deferredData' : 'performanceStats'"
            // Giving those props a default makes the ternary always truthy, so
            // every <Deferred> would wait on a key the page never sends and the
            // skeletons would never resolve. The undefined state is load-bearing.
            'vue/require-default-prop': 'off',

            'no-unused-vars': ['error', { args: 'after-used', caughtErrors: 'none', ignoreRestSiblings: true }],
        },
    },
    {
        // The service worker runs in its own global scope.
        files: ['resources/js/sw.js'],
        languageOptions: {
            globals: { ...globals.serviceworker },
        },
    },
    {
        files: ['tests/js/**/*.js', '*.config.js', 'vitest.setup.js'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            globals: {
                ...globals.browser,
                ...globals.node,
                // vitest.config.js sets globals: true.
                describe: 'readonly',
                it: 'readonly',
                expect: 'readonly',
                vi: 'readonly',
                beforeAll: 'readonly',
                beforeEach: 'readonly',
                afterAll: 'readonly',
                afterEach: 'readonly',
                route: 'readonly',
            },
        },
        rules: {
            'no-unused-vars': ['error', { args: 'after-used', caughtErrors: 'none', ignoreRestSiblings: true }],
        },
    },
]
