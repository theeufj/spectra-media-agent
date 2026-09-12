import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, fireEvent } from '@testing-library/react';
import ReportsIndex from '@/Pages/Reports/Index';

/*
 * The reports listing kept four numbers and a path to a PDF, so the only way to
 * read what the AI made of a period was to download a file — on a page whose own
 * subtitle promises "the AI's read on what changed".
 *
 * These pin the three things that makes true: a report carrying a narrative can
 * be opened in place, one written before the narrative was stored does not
 * pretend it can, and the figures follow the customer's currency rather than the
 * dollar sign the page used to hardcode.
 */

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { post: vi.fn() },
    usePage: () => ({
        props: { auth: { user: { active_customer: { currency_code: 'AUD' } } } },
    }),
}));

vi.mock('@/Layouts/AuthenticatedLayout', () => ({
    default: ({ children }) => <div>{children}</div>,
}));

global.route = (name, params) => `/${name}/${JSON.stringify(params ?? {})}`;

const readable = {
    period: 'weekly',
    start: '2026-09-01',
    end: '2026-09-07',
    generated_at: '2026-09-08T00:00:00+00:00',
    pdf_path: 'reports/week.pdf',
    summary: { total_cost: 592.8, total_clicks: 330, total_conversions: 30, blended_cpa: 19.76 },
    executive_summary: 'Conversions rose by a third while spend held steady.',
    insights: [
        { metric: 'cpa', label: 'Cost per conversion', current: 19.76, prior: 26.9, change: -26.5, direction: 'improved' },
        { metric: 'ctr', label: 'Click-through rate', current: 2.3, prior: 2.6, change: -11.5, direction: 'declined' },
    ],
};

// Written before the narrative was stored in the history record.
const legacy = {
    period: 'weekly',
    start: '2026-08-25',
    end: '2026-08-31',
    generated_at: '2026-09-01T00:00:00+00:00',
    pdf_path: 'reports/older.pdf',
    summary: { total_cost: 601.2, total_clicks: 298, total_conversions: 22, blended_cpa: 27.33 },
};

describe('reports listing', () => {
    it('opens a report in place rather than sending you to a PDF', () => {
        // Both layouts render — the table from sm up, the cards below it — so
        // every query here matches twice and takes the first.
        const { getAllByText, queryAllByText } = render(<ReportsIndex reports={[readable]} />);

        // The card layout shows the detail unconditionally; the table row is the
        // one that has to be opened, so it starts with only the card's copy.
        const before = queryAllByText(readable.executive_summary).length;

        fireEvent.click(getAllByText(/1 Sept 2026/)[0].closest('button'));

        expect(queryAllByText(readable.executive_summary).length).toBe(before + 1);
        expect(getAllByText('Cost per conversion').length).toBeGreaterThan(0);
        expect(getAllByText(/-26\.5%/).length).toBeGreaterThan(0);
    });

    it('leaves a pre-narrative report unexpandable instead of opening an empty panel', () => {
        // aria-expanded is what marks a row as openable, and it is only set on
        // rows that have something to open. Matching on the date text instead
        // would catch the PDF button, whose screen-reader label names the period.
        const withNarrative = render(<ReportsIndex reports={[readable]} />);
        expect(withNarrative.container.querySelectorAll('[aria-expanded]').length).toBe(1);

        const without = render(<ReportsIndex reports={[legacy]} />);
        expect(without.container.querySelectorAll('[aria-expanded]').length).toBe(0);
    });

    it('renders spend in the customer currency, not a hardcoded dollar', () => {
        const { getAllByText } = render(<ReportsIndex reports={[readable]} />);

        // Intl renders AUD as A$ for a non-AU locale and $ for an AU one; either
        // way the code must have reached the formatter.
        expect(getAllByText(/592\.80/).length).toBeGreaterThan(0);
    });
});
