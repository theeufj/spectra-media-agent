import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import CreativeSizesModal from '@/Components/CreativeSizesModal';

/**
 * The card said "3 sizes" and could not show them.
 *
 * One concept is generated as a square for feeds, 1200x628 for landscape
 * placements and 300x250 for display. The collateral page renders the square
 * only, so the other two existed in storage with no way to look at them — a
 * label making a promise the page could not keep.
 *
 * Worth seeing rather than trusting: the three are generated separately at
 * their own aspect ratios rather than cropped from one another, and the
 * headline is composited per size. Whether a 300x250 still reads is a question
 * only looking at it answers.
 */
const concept = {
    key: 'c1',
    images: [
        { id: 3, format: 'mrec', cloudfront_url: 'https://example.test/mrec.jpg' },
        { id: 1, format: 'square', cloudfront_url: 'https://example.test/square.jpg' },
        { id: 2, format: 'landscape', cloudfront_url: 'https://example.test/landscape.jpg' },
    ],
};

afterEach(() => vi.restoreAllMocks());

describe('the size viewer', () => {
    it('opens on the square, whatever order the rows arrive in', () => {
        // Rows come back in whatever order they were written; the square is
        // the one the scene was composed for and belongs first.
        render(<CreativeSizesModal concept={concept} show onClose={() => {}} />);

        expect(screen.getByAltText(/square version/i)).toBeInTheDocument();
    });

    it('offers every size the concept actually has', () => {
        render(<CreativeSizesModal concept={concept} show onClose={() => {}} />);

        expect(screen.getByRole('button', { name: '1024×1024' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: '1200×628' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: '300×250' })).toBeInTheDocument();
    });

    it('moves to the size that was asked for', () => {
        render(<CreativeSizesModal concept={concept} show onClose={() => {}} />);

        fireEvent.click(screen.getByRole('button', { name: '300×250' }));

        expect(screen.getByAltText(/mrec version/i)).toBeInTheDocument();
    });

    it('wraps rather than dead-ending at either edge', () => {
        render(<CreativeSizesModal concept={concept} show onClose={() => {}} />);

        // Back from the first size lands on the last, not on nothing.
        fireEvent.click(screen.getByRole('button', { name: /previous size/i }));

        expect(screen.getByAltText(/mrec version/i)).toBeInTheDocument();
    });

    it('renders nothing when a concept somehow has no images', () => {
        /*
           A format is skipped when its aspect fails to generate, so a concept
           can legitimately hold fewer than three — and in the worst case the
           viewer must not open on an empty frame.
        */
        const { container } = render(
            <CreativeSizesModal concept={{ key: 'x', images: [] }} show onClose={() => {}} />
        );

        expect(container).toBeEmptyDOMElement();
    });
});
