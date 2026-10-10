import { render, screen, cleanup } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import CampaignSpendSafetyCard from '../Components/CampaignSpendSafetyCard';

afterEach(cleanup);

describe('CampaignSpendSafetyCard', () => {
    it('shows the verified account currency and distinguishes a stop threshold from a billing cap', () => {
        render(<CampaignSpendSafetyCard campaign={{ spend_guardrails: { enabled: true, currency_code: 'AUD', max_spend_micros: 50_000_000, max_daily_budget_micros: 10_000_000, max_cpc_bid_micros: 3_000_000 } }} />);
        expect(screen.getByText(/AUD.*50\.00/)).toBeTruthy();
        expect(screen.getByText(/Google reporting can lag/)).toBeTruthy();
        expect(screen.getByText(/Automatic campaign changes are suspended/)).toBeTruthy();
    });

    it('does not claim an unverified pause succeeded', () => {
        const { rerender } = render(<CampaignSpendSafetyCard campaign={{ spend_safety_hold: { reason: 'trial_spend_cap_reached' } }} />);
        expect(screen.getByText(/Google confirmation is still pending/)).toBeTruthy();
        expect(screen.queryByText(/Google confirmed that this campaign is paused/)).toBeNull();
        rerender(<CampaignSpendSafetyCard campaign={{ spend_safety_hold: { reason: 'trial_spend_cap_reached', verified_at: '2026-10-10T01:00:00Z' } }} />);
        expect(screen.getByText(/Google confirmed that this campaign is paused/)).toBeTruthy();
    });
});
