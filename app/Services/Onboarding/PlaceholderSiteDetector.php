<?php

namespace App\Services\Onboarding;

use Illuminate\Support\Str;

/**
 * Does this content describe the customer's business, or the page standing
 * where their business should be?
 *
 * A signup in July entered lisbeth.com. It is a parked domain — the crawler
 * found a "Cherished Domains" premium-brokerage landing page, and the extractor
 * built a complete brand identity from it: voice "discreet, authoritative and
 * consultative", and a self-assessed quality of 94 out of 100. She created the
 * account at 14:37 and was gone at 14:38.
 *
 * The quality score was not lying. It measures how cleanly the page parsed, and
 * a parking page parses beautifully. Nothing anywhere asked whether the page
 * was hers.
 *
 * This does. It does not block anything — a false positive that stopped signup
 * would be worse than the bug. It records that the content looks like a
 * placeholder so the review screen can lead with "this doesn't look like your
 * business" instead of presenting someone else's with confidence.
 */
class PlaceholderSiteDetector
{
    /**
     * Phrases that only appear on a page selling or reserving the address
     * itself. Deliberately specific: "domain" alone would catch every web host
     * and half of IT services.
     *
     * @var list<string>
     */
    private const FOR_SALE = [
        'this domain is for sale',
        'this domain name is for sale',
        'domain is for sale',
        'buy this domain',
        'purchase this domain',
        'the owner of this domain',
        'make an offer on this domain',
        'inquire about this domain',
        'domain may be for sale',
        'interested in this domain',
        'checkout the full domain details',
    ];

    /**
     * Parking and marketplace operators. Their boilerplate is on the page
     * whatever the domain is.
     *
     * @var list<string>
     */
    private const PARKING_OPERATORS = [
        'hugedomains', 'afternic', 'sedo.com', 'dan.com', 'undeveloped.com',
        'domain broker', 'domain brokerage', 'premium domain',
        'godaddy domain', 'namecheap parking', 'parkingcrew', 'bodis.com',
        'sav.com', 'domainmarket.com', 'buydomains',
    ];

    /**
     * A site that exists but has not launched.
     *
     * @var list<string>
     */
    private const NOT_LAUNCHED = [
        'coming soon', 'under construction', 'site is under construction',
        'website coming soon', 'launching soon', 'opening soon',
        'this site is currently unavailable', 'account suspended',
        'default web page', 'index of /', 'apache2 ubuntu default page',
        'welcome to nginx', 'future home of something quite cool',
    ];

    /**
     * Below this, there is not enough text to describe a business — a parked
     * page is typically a headline and a contact form.
     */
    private const THIN_CONTENT_CHARS = 600;

    /**
     * @return string|null a sentence for the customer, or null if the content
     *                     looks like a real business
     */
    public function warningFor(string $content, ?string $website = null): ?string
    {
        $haystack = Str::of($content)->lower()->squish()->toString();

        if ($haystack === '') {
            return $this->sentence('We could not read anything at %s.', $website);
        }

        if ($this->containsAny($haystack, self::FOR_SALE)) {
            return $this->sentence(
                'The page at %s looks like a domain-for-sale listing rather than a business website.',
                $website,
            );
        }

        if ($this->containsAny($haystack, self::PARKING_OPERATORS)) {
            return $this->sentence(
                'The page at %s looks like a parked or unregistered domain rather than a business website.',
                $website,
            );
        }

        if ($this->containsAny($haystack, self::NOT_LAUNCHED)) {
            return $this->sentence(
                'The page at %s looks like a placeholder — the site may not be live yet.',
                $website,
            );
        }

        if (mb_strlen($haystack) < self::THIN_CONTENT_CHARS) {
            return $this->sentence(
                'There was very little text at %s, so what we found may not describe your business well.',
                $website,
            );
        }

        return null;
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function sentence(string $template, ?string $website): string
    {
        return sprintf($template, $website ?: 'that address');
    }
}
