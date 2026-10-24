<?php

namespace App\Services;

/** Narrow factual guard: a one-time service fee cannot erase separate usage charges. */
class AdCopyFeeScopeValidator
{
    private const EXTERNAL_CHARGE = '(?:(?:ad(?:vertising)?|media)\s+(?:spend|budget|costs?|clicks?|charges?|fees?)|(?:hosting|usage|transaction|processing|third-party|platform|provider|vendor)\s+(?:fees?|costs?|charges?|payments?|bills?))';

    public function validate(array $headlines, array $descriptions, array $evidence): array
    {
        $sources = $this->separateCostSources($evidence);
        $issues = [];
        if ($sources !== []) {
            foreach (['headlines' => $headlines, 'descriptions' => $descriptions] as $field => $assets) {
                foreach ($assets as $index => $asset) {
                    if (! is_string($asset)) {
                        continue;
                    }
                    foreach ($this->recurringClaims($this->text($asset)) as $claim) {
                        $qualifiers = $claim['qualifiers'];
                        if (! preg_match('/\b(?:fees?|charges?|costs?|payments?|bills?|spend|budget|no|without|zero)\b/iu', $claim['before'])) {
                            $qualifiers .= ' '.$claim['before'];
                        }
                        $after = preg_split('/[,;.!?]/u', $claim['after'], 2)[0];
                        if (preg_match('/^\s+(?:for|from|on)\s+(?:(?:our|the|this|your|their)\s+)?/iu', $after)) {
                            $qualifiers .= ' '.$after;
                        }
                        // An explicit service/agency scope does not claim the
                        // separate platform's usage charges are also waived.
                        $qualified = $this->serviceScope($qualifiers);
                        // A service qualifier cannot waive an additional,
                        // coordinated external object: "agency fees OR ad spend".
                        if ($this->waivesExternalCharges($claim['after'])) {
                            $qualified = false;
                        }
                        if ($qualified) {
                            continue;
                        }
                        $issues[] = ['code' => 'fee_scope_misleading_recurring_claim', 'field' => $field, 'index' => $index,
                            'message' => 'The asset "'.$asset.'" implies there are no recurring charges, but the advertised offer source establishes separate usage or media costs. Qualify any fee waiver as the agency/service fee and keep the separate charges clear. Another asset cannot qualify this claim.'];
                        break;
                    }
                }
            }
        }

        return ['is_valid' => $issues === [], 'separate_cost_sources' => $sources, 'issues' => $issues];
    }

    private function serviceScope(string $scope): bool
    {
        // Every coordinated object needs a service scope. An agency word
        // cannot qualify a separate platform/media object in the same waiver.
        $objects = preg_split('/\b(?:or|and)\b/iu', $scope) ?: [];
        if (count($objects) > 1) {
            foreach ($objects as $object) {
                if (! $this->serviceScope($object)) {
                    return false;
                }
            }

            return true;
        }
        // "Ad management" and "advertising agency" name the service, not
        // a waiver of the platform's advertising charges.
        if (preg_match('/\b(?:agency|management|retainer)\b/iu', $scope)) {
            return true;
        }

        return (bool) preg_match('/\b(?:service|setup|build|consulting|design|support|subscription|licen[cs]e)\b/iu', $scope)
            && ! preg_match('/\b(?:third-party|platform|provider|hosting|usage|processing|transaction|media|advertising|ads?)\b/iu', $scope);
    }

    private function waivesExternalCharges(string $after): bool
    {
        preg_match_all('/\b(?:or|and)\s+(?:(?:any|all|no|ongoing|recurring|monthly)\s+)?'.self::EXTERNAL_CHARGE.'\b/iu', $after, $objects, PREG_OFFSET_CAPTURE);
        foreach ($objects[0] as [$object, $offset]) {
            $continuation = substr($after, $offset + strlen($object));
            // A positive cost statement must directly qualify this object;
            // paying an unrelated hosting bill does not rescue waived ad spend.
            if (! preg_match('/^\s+(?:(?:is|are|remain|still|will\s+be)\s+)?(?:paid|billed|separate|additional)\b/iu', $continuation)) {
                return true;
            }
        }

        return false;
    }

    private function recurringClaims(string $text): array
    {
        preg_match_all('/\b(?:no|without|zero|free\s+of)\b(?<before>[^,.!?;\n]{0,80}?)\b(?:recurring|ongoing|monthly|annual|yearly|future)\s+(?<qualifiers>(?:[\pL-]+\s+){0,4}?)(?:charges?|fees?|costs?|payments?|bills?)\b(?=(?<after>[^.!?;\n]*))/iu', $text, $matches, PREG_SET_ORDER);
        $matches = array_filter($matches, fn ($match) => ! preg_match('/\b(?:but|however|although|yet|while|whereas)\b/iu', $match['before']));

        return array_values(array_map(fn ($match) => ['qualifiers' => trim($match['qualifiers']),
            'before' => trim($match['before']), 'after' => $match['after']], $matches));
    }

    private function separateCostSources(array $evidence): array
    {
        $landing = $this->pageKey($evidence['landing_page'] ?? null);
        $sources = [];
        foreach ($evidence['sources'] ?? [] as $source) {
            if (! is_array($source) || ! is_string($source['content'] ?? null)
                || ($landing !== null && $this->pageKey($source['url'] ?? null) !== $landing)) {
                continue;
            }
            foreach (preg_split('/(?<=[.!?])\s+|[\r\n;]+/u', $this->text($source['content'])) ?: [] as $sentence) {
                $external = '/\b'.self::EXTERNAL_CHARGE.'\b/iu';
                preg_match_all($external, $sentence, $terms, PREG_OFFSET_CAPTURE);
                foreach ($terms[0] as [$term, $offset]) {
                    $prefix = substr($sentence, 0, $offset);
                    if (preg_match('/\b(?:no|without|zero|free\s+of)\s+(?:(?:ongoing|recurring|monthly|annual|additional|extra)\s+)?$/iu', $prefix)) {
                        continue;
                    }
                    $media = preg_match('/^(?:ad(?:vertising)?|media)\s/iu', $term);
                    $ongoing = preg_match('/\b(?:ongoing|recurring|monthly|annual|yearly|per[- ](?:month|year|use|transaction))\b/iu', $sentence);
                    $separate = preg_match('/\b(?:separat(?:e|ely)|additional|extra|not\s+included|paid|pay|billed|ongoing|recurring)\b/iu', $sentence);
                    if ($separate && ($media || $ongoing)) {
                        $sources[] = is_string($source['url'] ?? null) ? $source['url'] : 'advertised offer source';
                        break 2;
                    }
                }
            }
        }

        return array_values(array_unique($sources));
    }

    private function text(string $text): string
    {
        return html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function pageKey(mixed $url): ?string
    {
        if (! is_string($url) || ! $parts = parse_url($url)) {
            return null;
        }
        if (empty($parts['host'])) {
            return null;
        }

        return strtolower($parts['host']).rtrim($parts['path'] ?? '', '/');
    }
}
