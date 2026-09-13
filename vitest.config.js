import { defineConfig } from 'vitest/config';

// Unit tests for the chat frontend's pure logic (formatting, reactions, …).
export default defineConfig({
    test: {
        include: ['resources/js/**/*.test.js'],
        environment: 'node',
    },
});
