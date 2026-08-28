import '@testing-library/jest-dom/vitest';

// route() is injected globally by Ziggy's @routes blade directive in the real
// app; tests get a stub that just echoes a recognisable path.
globalThis.route = (name, params) => {
    const suffix = params !== undefined && params !== null ? `/${params}` : '';

    return `/__route__/${name}${suffix}`;
};
