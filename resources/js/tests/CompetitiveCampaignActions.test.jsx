import React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import CompetitiveCampaignActions from '@/Components/CompetitiveCampaignActions';
const mocks = vi.hoisted(() => ({ post: vi.fn(), poll: { data: null, failureStreak: 0 } }));
vi.mock('@inertiajs/react', () => ({ Link: ({ children, href }) => <a href={href}>{children}</a>, router: { post: mocks.post } }));
vi.mock('@/hooks/usePolling', () => ({ usePolling: () => mocks.poll }));
const action = { id: 1, type: 'COMPETITOR_KEYWORD_TEST', status: 'pending', campaign_name: 'Search campaign',
    campaign_uuid: 'campaign-1', rationale: 'Relevant buyer intent', can_apply: true, change: { keywords: ['managed ads'] },
    evidence: [{ id: 'one', kind: 'competitive_strategy', observed_at: '2026-09-22T00:00:00Z' }] };
beforeEach(() => { mocks.post.mockClear(); mocks.poll = { data: null, failureStreak: 0 }; });
afterEach(cleanup);
describe('competitive campaign action report', () => {
    it('shows the actual proposed keyword and submits approval without claiming it is applied', () => {
        render(<CompetitiveCampaignActions initial={{ actions: [action] }} />);
        expect(screen.getByText('Awaiting review')).toBeVisible();
        expect(screen.getByText('Exact-match keywords: managed ads')).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Approve change' }));
        expect(mocks.post).toHaveBeenCalledTimes(1);
        expect(mocks.post.mock.calls[0][0]).toBe(route('strategy.war-room.recommendations.approve', 1));
        expect(screen.queryByText(/Platform state verified/)).toBeNull();
    });
    it('keeps an API-accepted change visibly unverified and then renders verified results from polling', () => {
        const { rerender } = render(<CompetitiveCampaignActions initial={{ actions: [{ ...action, status: 'applied' }] }} />);
        expect(screen.getByText('Applied · verifying')).toBeVisible();
        mocks.poll = { data: { actions: [{ ...action, status: 'verified', verified_at: '2026-09-22T01:00:00Z' }] } };
        rerender(<CompetitiveCampaignActions initial={{ actions: [{ ...action, status: 'applied' }] }} />);
        expect(screen.getByText('Verified · measuring results')).toBeVisible();
        expect(screen.queryByRole('button', { name: 'Approve change' })).toBeNull();
    });
    it('shows blocked actions without offering an execution button', () => {
        render(<CompetitiveCampaignActions initial={{ actions: [{ ...action, can_apply: false, message: 'Budget exceeds the approved limit.' }] }} />);
        expect(screen.getByText('Budget exceeds the approved limit.')).toBeVisible();
        expect(screen.queryByRole('button', { name: 'Approve change' })).toBeNull();
        expect(screen.getByRole('button', { name: 'Dismiss' })).toBeVisible();
    });
    it('does not invent a result when the measurement window lacks data', () => {
        render(<CompetitiveCampaignActions initial={{ actions: [{ ...action, status: 'measured', outcome: {
            summary: 'Not enough complete performance data to judge this change. No improvement is claimed.', before: {}, after: {},
        } }] }} />);
        expect(screen.getByText(/No improvement is claimed/)).toBeVisible();
    });
});
