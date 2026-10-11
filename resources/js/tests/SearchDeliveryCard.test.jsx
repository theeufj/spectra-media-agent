import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import SearchDeliveryCard, { searchDeliveryStatus } from '@/Components/SearchDeliveryCard';
import CampaignShow from '@/Pages/Campaigns/Show';
import CampaignDetail from '@/Pages/Admin/CampaignDetail';

const page = vi.hoisted(() => ({ props: { auth: { user: {} } }, url: '/' }));
const polling = vi.hoisted(() => ({ data: null, error: null }));
const pollingRequests = vi.hoisted(() => vi.fn());
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }) => <a href={href}>{children}</a>,
    usePage: () => page,
    useForm: (data = {}) => ({ data, errors: {}, setData: vi.fn(), reset: vi.fn(), put: vi.fn(), post: vi.fn(), processing: false }),
    router: { reload: vi.fn(), post: vi.fn(), visit: vi.fn() },
}));
vi.mock('@/Layouts/AuthenticatedLayout', () => ({ default: ({ header, children }) => <>{header}{children}</> }));
vi.mock('@/Pages/Admin/SideNav', () => ({ default: () => null }));
vi.mock('@/Components/PolicyStatusCard', () => ({ default: () => null }));
vi.mock('@/Components/CampaignCopilot', () => ({ default: () => null }));
vi.mock('@/Components/ForecastPanel', () => ({ default: () => null }));
vi.mock('@/hooks/useJobWatch', () => ({ useJobWatch: () => ({ phase: 'idle', data: null }) }));
vi.mock('@/hooks/usePolling', () => ({ usePolling: (url, options) => { pollingRequests(url, options); return polling; } }));

const state = {
    status: 'approval_required', checked_at: '2026-10-11T01:00:00Z', evaluation_started_at: '2026-10-10T12:00:00Z',
    stale_after_hours: 3, currency_code: 'AUD', mutation_allowed: false, blocked_reason: 'approved_bounded_trial',
    measurement: { from: '2026-10-10T12:00:00Z', through: '2026-10-11T00:00:00Z', complete_hours: 12, impressions: 3, clicks: 0, cost_micros: 0, conversions: 0 },
    diagnosis: { issues: [{ code: 'keyword_reach_limited', message: 'Current exact-match keywords have limited search demand.' }],
        forecast: { summary: 'The proposed terms may reach additional relevant searches.', campaign_resource: 'customers/secret/campaigns/secret' },
        proposal: { summary: 'Review related phrase-match searches while keeping the approved bid and budget limits.',
            candidate_keywords: [{ text: 'google ads management for small business', match_type: 'PHRASE', criterion_resource: 'customers/private/adGroupCriteria/private' }] } },
};
const campaign = { uuid: 'campaign-1', name: 'Search enquiries', status: 'active', daily_budget: 10, total_budget: 50,
    customer: { uuid: 'customer-1', name: 'Business', currency_code: 'AUD', users: [] }, strategies: [], search_delivery_state: state };

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-10-11T01:30:00Z'));
    page.props = { auth: { user: {} } };
    polling.data = null;
    polling.error = null;
    pollingRequests.mockClear();
});
afterEach(() => { cleanup(); vi.useRealTimers(); });

describe('SearchDeliveryCard', () => {
    it('quietly omits campaigns that have no delivery diagnosis yet', () => {
        const { container } = render(<SearchDeliveryCard campaign={{}} />);
        expect(container).toBeEmptyDOMElement();
    });

    it('shows the actual period and measured zeros while holding trial changes for approval', () => {
        render(<SearchDeliveryCard campaign={campaign} />);
        const card = screen.getByRole('region', { name: 'Google Search delivery diagnosis' });
        expect(within(card).getByText('Changes need your approval')).toBeVisible();
        expect(within(card).getByText(/12 complete reporting hours/)).toBeVisible();
        expect(within(card).getByText(/AUD.*0\.00/)).toBeVisible();
        expect(within(card).getByText('Current exact-match keywords have limited search demand.')).toBeVisible();
        expect(within(card).getByRole('list', { name: 'Proposed keywords' })).toHaveTextContent('google ads management for small business');
        expect(within(card).getByText('Phrase match')).toBeVisible();
        expect(within(card).getByText(/Keyword research and delivery checks continue/)).toBeVisible();
        expect(within(card).getByText(/A suggestion is not an applied change/)).toBeVisible();
        expect(within(card).getByText(/They are estimates, separate from the measured results/)).toBeVisible();
        expect(card.querySelector('time[datetime="2026-10-10T12:00:00Z"]')).toBeTruthy();
        expect(card.querySelector('time[datetime="2026-10-11T00:00:00Z"]')).toBeTruthy();
        expect(within(card).queryByRole('button')).toBeNull();
        expect(card).not.toHaveTextContent('customers/secret');
        expect(card).not.toHaveTextContent('customers/private');
    });

    it('keeps unavailable metrics distinct from measured zero and does not guess USD', () => {
        render(<SearchDeliveryCard campaign={{ search_delivery_state: { ...state, currency_code: null,
            measurement: { impressions: null, clicks: 0, cost_micros: 12_340_000, conversions: null } } }} />);
        expect(screen.getByText('Impressions').parentElement).toHaveTextContent('Not available');
        expect(screen.getByText('Recorded conversions').parentElement).toHaveTextContent('Not available');
        expect(screen.getByText('12.34 in ad account currency')).toBeVisible();
        expect(screen.getByText('0')).toBeVisible();
        expect(screen.queryByText(/USD/)).toBeNull();
    });

    it('compares forecast evidence separately from live results and explains blocked proposals', () => {
        render(<SearchDeliveryCard campaign={{ search_delivery_state: { ...state, diagnosis: { ...state.diagnosis,
            forecast: { success: true, period_days: 30, impressions: 17, clicks: 2.5, cost_micros: 4_300_000, auto_repair_safe: true },
            combined_forecast: { success: true, period_days: 30, impressions: 31, clicks: 7.2, cost_micros: 12_100_000, auto_repair_safe: false },
            proposal: { ...state.diagnosis.proposal, blocked_by: ['This controlled test requires review before keyword changes.'],
                suggested_actions: ['Review the bid ceiling and relevant keyword additions together.'] } } } }} />);
        expect(screen.getByText('Current keywords')).toBeVisible();
        expect(screen.getByText('With proposed keywords')).toBeVisible();
        expect(screen.getAllByText(/Estimate over 30 days/)).toHaveLength(2);
        expect(screen.getByText('2.5')).toBeVisible();
        expect(screen.getByText('7.2')).toBeVisible();
        expect(screen.getByText(/AUD.*4\.30/)).toBeVisible();
        expect(screen.getByText(/does not fully account for all live restrictions/)).toBeVisible();
        expect(screen.getByText('This controlled test requires review before keyword changes.')).toBeVisible();
        expect(screen.getByRole('list', { name: 'Recommended next steps' })).toHaveTextContent('Review the bid ceiling and relevant keyword additions together.');
        expect(screen.queryByRole('button')).toBeNull();
    });

    it.each(['approved_bounded_trial', 'approved bounded trial'])('translates and deduplicates saved hold reasons (%s) without repeating the footer', blockedReason => {
        render(<SearchDeliveryCard campaign={{ search_delivery_state: { ...state, blocked_reason: blockedReason,
            diagnosis: { ...state.diagnosis, proposal: { ...state.diagnosis.proposal,
                blocked_by: ['approved bounded trial', 'approved_bounded_trial',
                    'No researched addition improves reach.', 'No researched addition improves reach.'] } } } }} />);
        const card = screen.getByRole('region', { name: 'Google Search delivery diagnosis' });
        expect(within(card).getAllByText(/Automatic changes are on hold to preserve the approved test/)).toHaveLength(1);
        expect(within(card).getAllByText('No researched addition improves reach.')).toHaveLength(1);
        expect(card).not.toHaveTextContent('approved bounded trial');
        expect(card).not.toHaveTextContent('approved_bounded_trial');
        expect(within(card).getByText('Changes need your approval')).toBeVisible();
        expect(within(card).queryByRole('button')).toBeNull();
    });

    it('keeps applied changes unverified without positive post-repair impressions', () => {
        render(<SearchDeliveryCard campaign={{ search_delivery_state: { ...state, status: 'recovered',
            measurement: { ...state.measurement, impressions: 400 }, verification: { complete_hours: 4, impressions: 0 } } }} />);
        expect(screen.getByText('Checking traffic after the repair')).toBeVisible();
        expect(screen.getByText(/Applying changes alone does not prove recovery/)).toBeVisible();
        expect(screen.queryByText('Traffic recovery confirmed')).toBeNull();
    });

    it('shows recovery only for measured post-repair traffic while separating conversion outcomes', () => {
        render(<SearchDeliveryCard campaign={{ search_delivery_state: { ...state, status: 'recovered',
            verification: { complete_hours: 5, impressions: 9, clicks: 1 } } }} />);
        expect(screen.getByText('Traffic recovery confirmed')).toBeVisible();
        expect(screen.getByText(/Signups and sales still need to be assessed separately/)).toBeVisible();
        expect(screen.getByText(/5 complete reporting hours · 9 observed impressions/)).toBeVisible();
    });

    it('does not call an old positive snapshot healthy after the scheduled checks stop', () => {
        render(<SearchDeliveryCard campaign={{ search_delivery_state: { ...state, status: 'delivering', checked_at: '2026-10-10T20:00:00Z', stale: false } }} />);
        expect(screen.getByText('Delivery needs review')).toBeVisible();
        expect(screen.getByText(/This delivery check is out of date/)).toBeVisible();
        expect(screen.queryByText('Ads are receiving traffic')).toBeNull();
    });

    it('uses a configured staleness threshold and never treats a missing check as healthy', () => {
        expect(searchDeliveryStatus({ ...state, status: 'delivering', checked_at: '2026-10-10T23:00:00Z', stale_after_hours: 2 })).toBe('needs_review');
        expect(searchDeliveryStatus({ ...state, status: 'delivering', checked_at: '2026-10-10T23:00:00Z', stale_after_hours: 4 })).toBe('delivering');
        expect(searchDeliveryStatus({ ...state, status: 'delivering', checked_at: null })).toBe('needs_review');
    });

    it('ages the evidence while the page stays open even when polling has stopped', () => {
        render(<SearchDeliveryCard campaign={{ ...campaign, search_delivery_state: { ...state, status: 'delivering' } }} />);
        expect(screen.getByText('Ads are receiving traffic')).toBeVisible();
        act(() => vi.advanceTimersByTime(3 * 3_600_000));
        expect(screen.getByText('Delivery needs review')).toBeVisible();
        expect(screen.queryByText('Ads are receiving traffic')).toBeNull();
    });

    it('refreshes delivery evidence through the authenticated campaign response without hiding newer props', () => {
        const { rerender } = render(<SearchDeliveryCard campaign={campaign} />);
        polling.data = { ...campaign, search_delivery_state: { ...state, status: 'delivering', checked_at: '2026-10-11T01:20:00Z' } };
        rerender(<SearchDeliveryCard campaign={campaign} />);
        expect(screen.getByText('Ads are receiving traffic')).toBeVisible();
        rerender(<SearchDeliveryCard campaign={{ ...campaign, search_delivery_state: { ...state, status: 'needs_review', checked_at: '2026-10-11T01:25:00Z' } }} />);
        expect(screen.getByText('Delivery needs review')).toBeVisible();
        expect(screen.queryByText('Ads are receiving traffic')).toBeNull();
    });

    it('polls by public UUID every minute and rejects transport responses from another campaign', () => {
        render(<SearchDeliveryCard campaign={campaign} />);
        expect(pollingRequests).toHaveBeenCalledWith(route('api.campaigns.show', { campaign: campaign.uuid }),
            expect.objectContaining({ interval: 60_000, enabled: true, immediate: false }));
        const options = pollingRequests.mock.calls[0][1];
        expect(options.parse(campaign)).toBe(campaign);
        expect(() => options.parse({ ...campaign, uuid: 'another-campaign' })).toThrow('Delivery status could not be refreshed.');
        expect(() => options.parse({ uuid: campaign.uuid })).toThrow('Delivery status could not be refreshed.');
    });

    it('shows a lost live refresh as unavailable rather than preserving a green diagnosis', () => {
        const { rerender } = render(<SearchDeliveryCard campaign={{ ...campaign, search_delivery_state: { ...state, status: 'delivering' } }} />);
        expect(screen.getByText('Ads are receiving traffic')).toBeVisible();
        polling.error = new Error('private transport details');
        rerender(<SearchDeliveryCard campaign={{ ...campaign, search_delivery_state: { ...state, status: 'delivering' } }} />);
        expect(screen.getByText('Delivery check unavailable')).toBeVisible();
        expect(screen.queryByText('Ads are receiving traffic')).toBeNull();
        expect(screen.queryByText('private transport details')).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Refresh delivery status' }));
        expect(pollingRequests).toHaveBeenLastCalledWith(route('api.campaigns.show', { campaign: campaign.uuid }),
            expect.objectContaining({ restartKey: 1, immediate: true }));
    });

    it('does not retain a green state when the latest campaign response has no diagnosis', () => {
        const { rerender } = render(<SearchDeliveryCard campaign={{ ...campaign, search_delivery_state: { ...state, status: 'delivering' } }} />);
        polling.data = { ...campaign, search_delivery_state: null };
        rerender(<SearchDeliveryCard campaign={{ ...campaign, search_delivery_state: { ...state, status: 'delivering' } }} />);
        expect(screen.getByText('Delivery check unavailable')).toBeVisible();
        expect(screen.queryByText('Ads are receiving traffic')).toBeNull();
    });

    it('prioritizes read failures and never displays raw diagnostic errors', () => {
        render(<SearchDeliveryCard campaign={{ search_delivery_state: { ...state, status: 'recovered',
            verification: { impressions: 5 }, error: 'secret raw request payload' } }} />);
        expect(screen.getByText('Delivery check unavailable')).toBeVisible();
        expect(screen.getByText(/These figures do not establish that the campaign is healthy/)).toBeVisible();
        expect(screen.queryByText(/secret raw request payload/)).toBeNull();
        expect(screen.queryByText('Traffic recovery confirmed')).toBeNull();
    });

    it('keeps a partial repair failure actionable even if some impressions return', () => {
        render(<SearchDeliveryCard campaign={{ search_delivery_state: { ...state, status: 'recovered',
            repair: { errors: [{ code: 'keyword_update_failed', message: 'private platform error' }] }, verification: { impressions: 5 } } }} />);
        expect(screen.getByText('Delivery needs review')).toBeVisible();
        expect(screen.queryByText('Traffic recovery confirmed')).toBeNull();
        expect(screen.queryByText('private platform error')).toBeNull();
    });

    it.each([
        ['collecting_evidence', 'Gathering delivery evidence'], ['low_reach', 'Limited search reach'],
        ['repairing', 'Applying a delivery repair'], ['paused', 'Campaign is paused'], ['unexpected', 'Delivery check unavailable'],
    ])('renders %s without claiming successful delivery', (status, label) => {
        render(<SearchDeliveryCard campaign={{ search_delivery_state: { ...state, status } }} />);
        expect(screen.getByText(label)).toBeVisible();
        expect(screen.queryByText('Traffic recovery confirmed')).toBeNull();
    });

    it.each(['delivering', 'recovered'])('does not imply a spending hold when %s needs no repair', status => {
        render(<SearchDeliveryCard campaign={{ search_delivery_state: { ...state, status, blocked_reason: null,
            mutation_allowed: false, verification: { impressions: 7, complete_hours: 4 } } }} />);
        expect(screen.queryByText(/Automatic changes are on hold/)).toBeNull();
    });

    it('does not claim delivery research continues for an ordinary paused campaign', () => {
        render(<SearchDeliveryCard campaign={{ search_delivery_state: { ...state, status: 'paused', blocked_reason: null } }} />);
        expect(screen.getByText(/Automatic delivery repairs will not restart it/)).toBeVisible();
        expect(screen.queryByText(/Delivery research can continue/)).toBeNull();
        expect(screen.queryByText(/Automatic changes are on hold/)).toBeNull();
    });

    it.each([
        ['automatic_management_not_enabled', /Automatic campaign management is not enabled/],
        ['approved_budget_unverified', /approved daily budget needs to be verified/],
        ['existing_cpc_cap_required', /verified maximum cost-per-click bid is required/],
        ['forecasted_relevant_keywords_required', /No suitable keyword additions have been verified/],
        ['repair_attempt_limit', /reached its automatic repair attempt limit/],
        ['repair_did_not_restore_search_traffic', /repair has not restored measured Google Search traffic/],
        ['partial_repair_unresolved', /Some repair changes could not be confirmed/],
    ])('explains the backend blocker %s in customer language', (blockedReason, message) => {
        render(<SearchDeliveryCard campaign={{ search_delivery_state: { ...state, status: 'needs_review', blocked_reason: blockedReason } }} />);
        expect(screen.getByText(message)).toBeVisible();
        expect(screen.queryByText(blockedReason)).toBeNull();
    });

    it('renders current delivery diagnosis on the customer campaign page and refreshes with new props', () => {
        const { rerender } = render(<CampaignShow auth={{ user: {} }} campaign={campaign} />);
        expect(screen.getByRole('region', { name: 'Google Search delivery diagnosis' })).toHaveTextContent('Changes need your approval');
        rerender(<CampaignShow auth={{ user: {} }} campaign={{ ...campaign, search_delivery_state: { ...state, status: 'delivering', mutation_allowed: true, blocked_reason: null } }} />);
        expect(screen.getByRole('region', { name: 'Google Search delivery diagnosis' })).toHaveTextContent('Ads are receiving traffic');
    });

    it('renders the diagnosis at campaign level above admin strategies', () => {
        page.props = { campaign };
        render(<CampaignDetail auth={{ user: {} }} />);
        const card = screen.getByRole('region', { name: 'Google Search delivery diagnosis' });
        const strategies = screen.getByText('Strategies (0)');
        expect(card).toHaveTextContent('Changes need your approval');
        expect(card.compareDocumentPosition(strategies) & window.Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });
});
