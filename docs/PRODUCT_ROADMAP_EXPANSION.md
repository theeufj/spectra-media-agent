# 🚀 Spectra Platform Expansion Roadmap

This document outlines a feature roadmap to transform Spectra from a "content generator" into a comprehensive "Autonomous Ad Agency."

## Phase 1: Deep Intelligence (The "Know Me" Phase)
*Enhancing how we understand the user's business from just a URL.*

### 1. Visual Brand DNA Extractor ✅ **IMPLEMENTED**
**Status:** Fully operational and tested with production data.

**Implementation Details:**
- **Service:** `BrandGuidelineExtractorService` with full Gemini Vision AI integration
- **Database:** `brand_guidelines` table with vector embeddings support
- **Screenshot Engine:** Browsershot for high-quality page captures
- **AI Analysis:** Gemini Pro Vision extracts:
    - ✅ Primary/Secondary Color Hex Codes with usage notes
    - ✅ Font Families (Arial, Helvetica, custom fonts detected)
    - ✅ Typography styles (heading and body text analysis)
    - ✅ Visual Style classification (modern, minimalist, vibrant, etc.)
    - ✅ Brand Voice and tone attributes with examples
    - ✅ Quality score for extraction confidence (0-100)
- **Benefit:** Zero-friction onboarding. User enters URL → Automatic Brand Bible generation
- **Test Coverage:** Integration test with Cloudflare.com validates full end-to-end flow
- **Performance:** ~30-60 seconds for complete analysis including screenshot + AI processing
- **Subscription Limits:** 
  - **Free:** 1 brand guideline extraction per customer
  - **Pro:** Unlimited extractions with re-generation capability

### 2. Competitor "War Room"
**Concept:** User provides 3 competitor URLs. We analyze them to find gaps.
- **How:**
    - Crawl competitor sitemaps.
    - Extract their "Unique Selling Propositions" (USPs) from their H1s and Meta Descriptions.
    - **Output:** A "Counter-Strategy" module. *("Competitor X focuses on 'Speed'. We should focus on 'Quality' to differentiate.")*

### 3. Landing Page CRO Audit ✅ **IMPLEMENTED**
**Status:** Fully operational and integrated into website crawling workflow.

**Implementation Details:**
- **Service:** `LandingPageCROAuditService` with comprehensive CRO analysis
- **Database:** `landing_page_audits` table with performance and conversion metrics
- **Integration:** Automatically runs during `CrawlPage` job for product/money pages
- **AI-Powered:** Uses Gemini AI for message match analysis

**Automated Checks:**
- ✅ **Page Speed Analysis:**
  - Load time tracking
  - Page size calculation (KB)
  - DOM element count
  - Core Web Vitals estimation (LCP, FID, CLS)
  
- ✅ **Above-the-Fold CTA Detection:**
  - Automatic button/link detection
  - CTA positioning analysis
  - Primary CTA identification
  - CTA count optimization check

- ✅ **Message Match Analysis (AI):**
  - Headline clarity scoring (0-100)
  - Value proposition analysis
  - Keyword extraction
  - Conversion-focused messaging evaluation

**Output:** 
- Comprehensive "Fix List" with categorized issues (performance, CTA, messaging)
- Actionable recommendations with priority levels (critical, high, medium)
- Overall CRO health score (0-100)
- Automatically generated before ad campaign deployment

**Trigger:** Runs automatically during sitemap crawl for all customer pages identified as product/money/landing pages

**Performance:** ~2-5 seconds per page audit (including AI analysis)

**Subscription Limits:**
- **Free:** 3 CRO audits per customer
- **Pro:** Unlimited audits across all landing pages

---

## Phase 2: Creative Powerhouse (The "Show Me" Phase)
*Generating professional-grade assets automatically.*

### 4. Smart Asset Harvesting & Remixing
**Concept:** Use the client's existing high-quality assets instead of generic stock.
- **How:**
    - Scrape all images > 1000px width from the site.
    - Use AI to **Remove Backgrounds** from product photos.
    - Use **Generative Fill/Outpainting** to resize product shots for different formats (e.g., turn a square product shot into a 9:16 Story format).

### 5. Dynamic Video Generation (From Static Assets)
**Concept:** Turn static product pages into video ads.
- **How:**
    - Take the scraped product images + extracted USPs.
    - Use a template engine (FFmpeg or Remotion) to animate them:
        - *Slide 1:* Product Image (Zoom effect).
        - *Slide 2:* "5-Star Rated" (Text overlay).
        - *Slide 3:* "Shop Now" (CTA).
    - Sync with AI-generated voiceover (already implemented).

---

## Phase 3: Strategic Orchestration (The "Guide Me" Phase)
*Moving from "making ads" to "planning campaigns."*

### 6. Omni-Channel Budget Allocator
**Concept:** Users often ask "Where should I spend my $5,000?"
- **How:** An AI logic layer that recommends splits based on business type.
    - *E.g., "Detected 'SaaS B2B'. Recommendation: 60% LinkedIn, 30% Google Search, 10% Retargeting."*

### 7. Audience Persona Builder
**Concept:** Deeply understand *who* buys.
- **How:** Analyze the language on the "Reviews" or "Testimonials" page.
- **Output:** Generate detailed User Personas (e.g., "Budget-Conscious Brenda," "Enterprise Eric") and tailor ad copy specifically for each persona.

---

## Phase 4: Execution & Automation (The "Do It For Me" Phase)
*Closing the loop.*

### 8. One-Click Publishing (API Integrations)
**Concept:** Push the generated campaigns directly to the ad platforms.
- **Integrations:**
    - **Google Ads API:** Create Campaigns, Ad Groups, Keywords, and Responsive Search Ads.
    - **Meta Marketing API:** Create Campaigns, Ad Sets (Targeting), and Creative Ads.
- **Benefit:** Removes the manual "Copy/Paste" work for the user.

### 9. Auto-Pilot Optimization
**Concept:** The system monitors live performance (via webhooks/API).
- **Actions:**
    - *Low CTR?* Auto-generate new headlines and swap them in.
    - *High CPA?* Pause the underperforming ad set.
    - *Winner Found?* Shift budget to the winning variation.
