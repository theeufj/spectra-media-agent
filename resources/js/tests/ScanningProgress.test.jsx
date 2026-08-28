import React from 'react';
import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';
import { ScanningProgress } from '@/Pages/QuickStart/Scanning';

describe('ScanningProgress', () => {
    it('narrates the crawl phase before any pages have landed', () => {
        const { getByText, getByLabelText } = render(
            <ScanningProgress phase="watching" data={{ pages: 0 }} website="https://acme.example" />
        );

        expect(getByText('Setting up https://acme.example')).toBeInTheDocument();
        expect(getByText('Finding your pages…')).toBeInTheDocument();
        // The guideline step hasn't started yet.
        expect(getByText('Building your brand guidelines').className).toContain('text-gray-400');
        expect(getByLabelText('working')).toBeInTheDocument();
    });

    it('advances to the guideline phase once pages are read', () => {
        const { getByText, getByLabelText } = render(
            <ScanningProgress phase="watching" data={{ pages: 12 }} website="https://acme.example" />
        );

        expect(getByLabelText('done')).toBeInTheDocument();
        expect(getByText('12 pages read')).toBeInTheDocument();
        expect(getByText('Building your brand guidelines').className).not.toContain('text-gray-400');
    });

    it('shows the failure reason and the manual escape hatch', () => {
        const { getByText } = render(
            <ScanningProgress phase="failed" data={{ failed: true, failure_reason: 'Your site blocked our scanner.' }} />
        );

        expect(getByText(/Your site blocked our scanner\./)).toBeInTheDocument();
        expect(getByText('Add content manually')).toBeInTheDocument();
    });

    it('tells a dead session to refresh instead of spinning forever', () => {
        const { getByText } = render(<ScanningProgress phase="disconnected" data={null} />);

        expect(getByText('We lost the connection')).toBeInTheDocument();
        expect(getByText('Refresh')).toBeInTheDocument();
    });

    it('offers the dashboard exit on timeout without pretending anything failed', () => {
        const { getByText, queryByText } = render(
            <ScanningProgress phase="timeout" data={{ pages: 20 }} />
        );

        expect(getByText('This is taking longer than usual')).toBeInTheDocument();
        expect(getByText('Go to dashboard')).toBeInTheDocument();
        expect(queryByText(/couldn't finish/)).toBeNull();
    });
});
