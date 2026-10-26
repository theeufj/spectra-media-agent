import { render, screen, cleanup } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import CampaignSpendSafetyCard from '../Components/CampaignSpendSafetyCard';

afterEach(cleanup);

describe('CampaignSpendSafetyCard', () => {
    it.each(['revoked', 'cancelled'])('does not describe a %s request as waiting for activation', status => {
        render(<CampaignSpendSafetyCard campaign={{ search_delivery_state: { ai_max_request: { enabled: true, status } } }} />);
        expect(screen.getByText('Google AI Max setup stopped')).toBeTruthy();
        expect(screen.getByText(/No automatic restart will be attempted/)).toBeTruthy();
        expect(screen.queryByText(/Activation is waiting/)).toBeNull();
    });

    it('shows pending purchase validation without claiming native bidding is running', () => {
        render(<CampaignSpendSafetyCard campaign={{ search_delivery_state: { ai_max_request: { enabled: true, status: 'awaiting_delivery_validation' } } }} />);
        expect(screen.getByText('Preparing Google AI Max')).toBeTruthy();
        expect(screen.getByText(/Activation is waiting for purchase-import validation/)).toBeTruthy();
        expect(screen.queryByText('Google AI Max pilot')).toBeNull();
        expect(screen.queryByText(/Conversion bidding has no per-click price cap/)).toBeNull();
    });

    it('shows the native pilot spending policy without presenting the saved CPC ceiling as active', () => {
        render(<CampaignSpendSafetyCard campaign={{ spend_guardrails: {
            automation_limits: { enabled: true, max_cpc_bid_micros: 10_000_000 },
            ai_max_pilot: { enabled: true, currency_code: 'AUD', max_daily_budget_micros: 50_000_000, review_spend_micros: 200_000_000, ends_at: '2026-11-09T08:00:00+11:00' },
        }, search_delivery_state: { ai_max_monitor: { reported_spend_micros: 0, checked_at: '2026-10-26T20:00:00+11:00' } } }} />);
        expect(screen.getByText('Google AI Max pilot')).toBeTruthy();
        expect(screen.getByText(/AUD.*200\.00/)).toBeTruthy();
        expect(screen.getByText(/^AUD\s+0\.00$/)).toBeTruthy();
        expect(screen.getByText(/Conversion bidding has no per-click price cap/)).toBeTruthy();
        expect(screen.getByText(/review threshold is not a guaranteed billing cap/)).toBeTruthy();
        expect(screen.queryByText('Maximum CPC bid')).toBeNull();
        expect(screen.queryByText(/Awaiting Google report/)).toBeNull();
    });

    it('distinguishes unavailable native spending from zero and shows the actual pause confirmation', () => {
        render(<CampaignSpendSafetyCard campaign={{ spend_guardrails: { ai_max_pilot: { enabled: true, currency_code: 'AUD', max_daily_budget_micros: 50_000_000, review_spend_micros: 200_000_000 } },
            spend_safety_hold: { reason: 'ai_max_reported_spend_review_reached', verified_at: '2026-10-26T20:00:00+11:00' } }} />);
        expect(screen.getByText('Awaiting Google report')).toBeTruthy();
        expect(screen.getByText(/AI Max pilot reached its spending review threshold/)).toBeTruthy();
        expect(screen.getByText(/Google confirmed that this campaign is paused/)).toBeTruthy();
    });

    it('keeps observed zero purchases separate from an unavailable acquisition cost', () => {
        render(<CampaignSpendSafetyCard campaign={{ spend_guardrails: { ai_max_pilot: { enabled: true, currency_code: 'AUD', max_daily_budget_micros: 50_000_000, review_spend_micros: 200_000_000 } },
            ai_max_performance: { status: 'observed', currency_code: 'AUD', period: { from: '2026-10-28', through: '2026-10-29' }, metrics: { conversions: 0, cost_micros: 40_000_000, cpa_micros: null }, combinations: { rows: [] } } }} />);
        expect(screen.getByText('Attributed setup purchases')).toBeTruthy();
        expect(screen.getByText('0')).toBeTruthy();
        expect(screen.getByText('Not available yet')).toBeTruthy();
        expect(screen.getByText(/Complete Google account days: 2026-10-28 to 2026-10-29/)).toBeTruthy();
    });

    it('does not turn a performance-report failure into zero purchases', () => {
        render(<CampaignSpendSafetyCard campaign={{ spend_guardrails: { ai_max_pilot: { enabled: true, currency_code: 'AUD', max_daily_budget_micros: 50_000_000, review_spend_micros: 200_000_000 } },
            ai_max_performance: { status: 'unavailable', metrics: null, period: null } }} />);
        expect(screen.getByText(/Purchase reporting is temporarily unavailable/)).toBeTruthy();
        expect(screen.queryByText('Attributed setup purchases')).toBeNull();
    });

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
