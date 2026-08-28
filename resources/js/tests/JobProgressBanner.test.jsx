import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, fireEvent } from '@testing-library/react';
import JobProgressBanner from '@/Components/JobProgressBanner';

describe('JobProgressBanner', () => {
    it('renders nothing without a state', () => {
        const { container } = render(<JobProgressBanner state={null} title="x" />);
        expect(container).toBeEmptyDOMElement();
    });

    it('shows a spinner while running and hides the dismiss control', () => {
        const { getByTestId, queryByLabelText, getByText } = render(
            <JobProgressBanner state="running" title="Working…" message="hold on" onDismiss={() => {}} />
        );

        expect(getByTestId('job-spinner')).toBeInTheDocument();
        expect(getByText('Working…')).toBeInTheDocument();
        expect(getByText('hold on')).toBeInTheDocument();
        // A user must not dismiss the only signal that work is in flight.
        expect(queryByLabelText('Dismiss')).toBeNull();
    });

    it('lets terminal states be dismissed', () => {
        const onDismiss = vi.fn();
        const { getByLabelText } = render(
            <JobProgressBanner state="failed" title="Failed" message="why" onDismiss={onDismiss} />
        );

        fireEvent.click(getByLabelText('Dismiss'));
        expect(onDismiss).toHaveBeenCalledTimes(1);
    });

    it('renders each terminal state with its message', () => {
        for (const state of ['done', 'failed', 'timeout']) {
            const { getByText, unmount } = render(
                <JobProgressBanner state={state} title={`t-${state}`} message={`m-${state}`} />
            );
            expect(getByText(`t-${state}`)).toBeInTheDocument();
            expect(getByText(`m-${state}`)).toBeInTheDocument();
            unmount();
        }
    });
});
