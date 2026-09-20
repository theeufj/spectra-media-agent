import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, renderHook, act } from '@testing-library/react';
import AdPreview from '@/Components/AdPreview';
import { SetupStages, HandoverChecklist } from '@/Components/SetupJourney';
import { useCollateralGeneration } from '@/hooks/useCollateralGeneration';

const poll = vi.hoisted(() => ({ data: null, failureStreak: 0 }));
vi.mock('@/hooks/usePolling', () => ({ usePolling: () => poll }));
vi.mock('@inertiajs/react', () => ({ Link: ({ href, children }) => <a href={href}>{children}</a> }));

describe('setup review', () => {
    it('previews only Search for a Search strategy', () => {
        const { getByText, queryByText } = render(<AdPreview campaignType="search" platform="Google Ads (SEM)" websiteUrl="https://realpropertyads.com" adCopy={{ headlines: ['Your listing, promoted'], descriptions: ['Property marketing for agents.'] }} />);
        expect(getByText('Your listing, promoted')).toBeInTheDocument();
        expect(queryByText('Facebook Feed')).toBeNull();
        expect(queryByText('Google Display')).toBeNull();
    });
    it('announces the current stage and the unconfirmed handover items', () => {
        const { getByText, getByLabelText } = render(<><SetupStages stage={2} /><HandoverChecklist items={[{ title: 'Invitation', done: true, detail: 'Sent; acceptance is not confirmed.' }, { title: 'Google billing', done: false, detail: 'Confirm in Google Ads.' }]} /></>);
        expect(getByText('Review your ads')).toHaveAttribute('aria-current', 'step');
        expect(getByLabelText('Action needed')).toBeInTheDocument();
        expect(getByText('Sent; acceptance is not confirmed.')).toBeInTheDocument();
    });
    it('stops when server reports completion but waits for an explicitly requested regeneration', () => {
        poll.data = null;
        const original = { id: 1, updated_at: 'before' };
        const { result, rerender } = renderHook(() => useCollateralGeneration({ currentStrategy: { uuid: 'strategy', id: 1 }, adCopy: original, imageCollaterals: [], videoCollaterals: [], generationPending: true }));
        act(() => { result.current.setGeneratingAdCopy(true); });
        poll.data = { adCopy: original, imageCollaterals: [], videoCollaterals: [], generationPending: false, collateralErrors: [] };
        rerender();
        expect(result.current.isPolling).toBe(true);
        poll.data = { ...poll.data, adCopy: { ...original, updated_at: 'after' } };
        rerender();
        expect(result.current.isPolling).toBe(false);
        expect(result.current.generatingAdCopy).toBe(false);
    });
});
