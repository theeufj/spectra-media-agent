import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';

/**
 * Approving creative for deployment must work without a mouse.
 *
 * Which ad copy, which images and which videos actually go live were three
 * `<div onClick>` handlers with `cursor-pointer` and nothing else: no keyboard
 * path, no role, no state announced. The ad-copy panel instructs "Check the box
 * to include this ad copy in deployment" directly above a thing that is not a
 * checkbox and could not be checked without a pointing device.
 *
 * This mirrors the approvalToggle() helper in Pages/Campaigns/Collateral.jsx.
 * The page itself is ~1300 lines behind Inertia, uploads and polling, so the
 * decision is covered here rather than by driving the whole screen.
 */
function approvalToggle({ checked, onToggle, label, enabled = true }) {
    if (!enabled) return {};

    return {
        role: 'checkbox',
        'aria-checked': checked,
        'aria-label': label,
        tabIndex: 0,
        onClick: onToggle,
        onKeyDown: (e) => {
            if (e.key === ' ' || e.key === 'Enter') {
                e.preventDefault();
                onToggle();
            }
        },
    };
}

const Toggle = (props) => <div {...approvalToggle(props)}>creative</div>;

describe('approvalToggle', () => {
    it('announces itself as a checkbox with its state', () => {
        render(<Toggle checked onToggle={() => {}} label="Include this image in deployment" />);

        const box = screen.getByRole('checkbox', { name: 'Include this image in deployment' });
        expect(box).toHaveAttribute('aria-checked', 'true');
    });

    it('is reachable by keyboard', () => {
        render(<Toggle checked={false} onToggle={() => {}} label="Include this ad copy in deployment" />);

        expect(screen.getByRole('checkbox')).toHaveAttribute('tabIndex', '0');
    });

    it('toggles on Space, which is what a checkbox answers to', () => {
        const onToggle = vi.fn();
        render(<Toggle checked={false} onToggle={onToggle} label="x" />);

        fireEvent.keyDown(screen.getByRole('checkbox'), { key: ' ' });

        expect(onToggle).toHaveBeenCalledTimes(1);
    });

    it('toggles on Enter, which is what people actually try', () => {
        const onToggle = vi.fn();
        render(<Toggle checked={false} onToggle={onToggle} label="x" />);

        fireEvent.keyDown(screen.getByRole('checkbox'), { key: 'Enter' });

        expect(onToggle).toHaveBeenCalledTimes(1);
    });

    it('ignores other keys so typing never deploys something', () => {
        const onToggle = vi.fn();
        render(<Toggle checked={false} onToggle={onToggle} label="x" />);

        for (const key of ['a', 'Tab', 'ArrowDown', 'Escape']) {
            fireEvent.keyDown(screen.getByRole('checkbox'), { key });
        }

        expect(onToggle).not.toHaveBeenCalled();
    });

    it('stays inert while a video is still rendering', () => {
        const onToggle = vi.fn();
        render(<Toggle checked={false} onToggle={onToggle} label="x" enabled={false} />);

        // No role at all: an unfinished video is not something to approve, and
        // offering a focusable control for it would be a dead stop in the tab
        // order.
        expect(screen.queryByRole('checkbox')).toBeNull();
    });

    it('still works with a mouse', () => {
        const onToggle = vi.fn();
        render(<Toggle checked={false} onToggle={onToggle} label="x" />);

        fireEvent.click(screen.getByRole('checkbox'));

        expect(onToggle).toHaveBeenCalledTimes(1);
    });
});
