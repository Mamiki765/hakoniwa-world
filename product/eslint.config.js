import eslint from '@eslint/js';
import typescriptParser from '@typescript-eslint/parser';
import globals from 'globals';
import pluginVue from 'eslint-plugin-vue';

export default [
    { ignores: ['public/build/**'] },
    eslint.configs.recommended,
    ...pluginVue.configs['flat/recommended'],
    {
        files: ['resources/js/**/*.ts'],
        languageOptions: {
            parser: typescriptParser,
            globals: globals.browser,
        },
        rules: {
            'no-undef': 'off',
            'no-unused-vars': 'off',
        },
    },
    {
        files: ['resources/js/**/*.vue'],
        languageOptions: {
            globals: globals.browser,
            parserOptions: {
                parser: typescriptParser,
                sourceType: 'module',
            },
        },
        rules: {
            'vue/multi-word-component-names': 'off',
            'vue/html-indent': 'off',
            'vue/max-attributes-per-line': 'off',
            'vue/singleline-html-element-content-newline': 'off',
            'vue/html-self-closing': 'off',
            'no-undef': 'off',
            'no-unused-vars': 'off',
        },
    },
];
