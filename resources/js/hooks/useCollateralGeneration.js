import { useEffect, useRef, useState } from 'react';
import { usePolling } from '@/hooks/usePolling';
import { parseCollateral } from '@/contracts/campaign';

export function useCollateralGeneration({ currentStrategy, adCopy, imageCollaterals, videoCollaterals, generationPending }) {
    const [generatingAdCopy, setGeneratingAdCopy] = useState(false);
    const [generatingImage, setGeneratingImage] = useState(false);
    const [generatingVideo, setGeneratingVideo] = useState(false);
    const [collateral, setCollateral] = useState({ adCopy, imageCollaterals, videoCollaterals });
    const [isPolling, setIsPolling] = useState(generationPending);
    const [collateralError, setCollateralError] = useState(null);
    // Refs for values accessed inside the polling interval to avoid stale closures
    // and prevent the effect from restarting (which resets the safety timeout).
    const collateralRef = useRef(collateral);
    const generatingAdCopyRef = useRef(generatingAdCopy);
    const generatingImageRef = useRef(generatingImage);
    const generatingVideoRef = useRef(generatingVideo);
    // Track how many errors existed when this poll session started so we only
    // surface errors that are genuinely new (pre-existing errors must not stop
    // subsequent polls, e.g. during video extension after an earlier failure).
    const errorCountAtPollStartRef = useRef(0);

    useEffect(() => { collateralRef.current = collateral; }, [collateral]);
    useEffect(() => { generatingAdCopyRef.current = generatingAdCopy; }, [generatingAdCopy]);
    useEffect(() => { generatingImageRef.current = generatingImage; }, [generatingImage]);
    useEffect(() => { generatingVideoRef.current = generatingVideo; }, [generatingVideo]);

    // Reset the error baseline whenever a new poll session starts.
    useEffect(() => {
        if (isPolling) {
            // Sentinel — set to the real baseline on the first poll response.
            errorCountAtPollStartRef.current = -1;
        }
    }, [isPolling]);

    // Transport for the poll below.
    //
    // This was setInterval + raw fetch whose only failure handling was
    // console.error, so a dropped session or a deploy mid-generation left the
    // spinners turning with nothing said until a five-minute timeout silently
    // cleared them — the user saw generation "finish" having produced nothing.
    // usePolling skips overlapping requests, cleans up on unmount, and counts
    // consecutive failures so the page can say it lost contact.
    const { data: polled, failureStreak } = usePolling(
        isPolling ? route('api.collateral.show', { strategy: currentStrategy.uuid }) : null,
        { interval: 3000, parse: parseCollateral }
    );

    useEffect(() => {
        if (failureStreak >= 4 && isPolling) {
            setCollateralError('Lost contact with the server while generating. Reload the page to see what completed.');
            setIsPolling(false);
            setGeneratingAdCopy(false);
            setGeneratingImage(false);
            setGeneratingVideo(false);
        }
    }, [failureStreak, isPolling]);

    // Apply each poll response. Reads current collateral through a ref so this
    // does not re-run (and re-diff) every time it sets state.
    useEffect(() => {
        if (!isPolling || !polled) return;

        const data = polled;
        const current = collateralRef.current;

        // Capture the baseline error count on the first response of this session
        const currentErrorCount = data.collateralErrors?.length ?? 0;
        if (errorCountAtPollStartRef.current === -1) {
            errorCountAtPollStartRef.current = currentErrorCount;
        }

        // Stop polling and surface error only if NEW errors appeared since this poll session started
        if (currentErrorCount > errorCountAtPollStartRef.current) {
            const latest = data.collateralErrors[data.collateralErrors.length - 1];
            setCollateralError(latest.message || 'Collateral generation failed. Please try regenerating.');
            setIsPolling(false);
            setGeneratingAdCopy(false);
            setGeneratingImage(false);
            setGeneratingVideo(false);
            return;
        }

        // Update all collateral data
        const hasNewAdCopy = data.adCopy && (!current.adCopy || data.adCopy.updated_at !== current.adCopy?.updated_at);
        const hasNewImages = JSON.stringify(data.imageCollaterals) !== JSON.stringify(current.imageCollaterals);
        const hasNewVideos = JSON.stringify(data.videoCollaterals) !== JSON.stringify(current.videoCollaterals);

        if (hasNewAdCopy || hasNewImages || hasNewVideos) {
            setCollateral(data);

            // Stop polling for ad copy if it was generated
            if (hasNewAdCopy && generatingAdCopyRef.current) {
                setGeneratingAdCopy(false);
            }

            // Stop image generation flag if new images arrived
            if (hasNewImages && generatingImageRef.current) {
                setGeneratingImage(false);
            }

            // Stop video generation flag if new videos arrived
            if (hasNewVideos && generatingVideoRef.current) {
                setGeneratingVideo(false);
            }
        }
    }, [polled, isPolling]);

    // Safety net: stop after five minutes rather than spinning forever.
    //
    // This used to clear the generating flags and say nothing, so a stalled
    // generation was indistinguishable from a finished one that produced
    // nothing. It now says what happened.
    useEffect(() => {
        if (!isPolling) return;

        const timeout = setTimeout(() => {
            setCollateralError('Generation is taking longer than expected. Reload the page to see what completed, or try regenerating.');
            setIsPolling(false);
            setGeneratingAdCopy(false);
            setGeneratingImage(false);
            setGeneratingVideo(false);
        }, 300000);

        return () => clearTimeout(timeout);
    }, [isPolling, currentStrategy.id]);

    return { generatingAdCopy, setGeneratingAdCopy, generatingImage, setGeneratingImage, generatingVideo, setGeneratingVideo, collateral, setCollateral, isPolling, setIsPolling, collateralError, setCollateralError };
}
