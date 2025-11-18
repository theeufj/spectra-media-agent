# Spectra Media Agent - Implementation TODO

**Last Updated:** November 18, 2025  
**Priority:** Critical bugs and integrations for Brand Guideline system

---

## 🔴 HIGH PRIORITY - Critical Bug Fixes

### 1. Fix AdminMonitorService Approval Threshold
**File:** `app/Services/AdminMonitorService.php`  
**Line:** 124  
**Current:** ~~`> 50` (too lenient)~~ → **FIXED: `> 75`**  
**Required:** `> 75` (recommended minimum)  
**Impact:** Poor quality content (scores 51-74) is being auto-approved  
**Status:** ✅ **COMPLETE**

**Implementation:** Changed threshold from 50 to 75. Content must now score >75 to be auto-approved.

---

### 2. Fix Google SEM Description Length
**File:** `config/platform_rules.php`  
**Line:** 31  
**Current:** ~~`'description_max_length' => 95`~~ → **FIXED: `90`**  
**Required:** `'description_max_length' => 90`  
**Impact:** Generated ad copy may violate Google Ads character limits  
**Validation:** Google Ads allows max 90 characters for descriptions  
**Status:** ✅ **COMPLETE**

---

### 3. Fix ApplySeasonalStrategyShift Placeholder Data
**File:** `app/Jobs/ApplySeasonalStrategyShift.php`  
**Lines:** 44-48  
**Current:** ~~Hardcoded placeholder values~~ → **FIXED**  
**Status:** ✅ **COMPLETE**

**Implementation:**
- ✅ Fetches `total_budget` from campaigns table
- ✅ Fetches bidding strategy from latest strategy record (JSON parsed)
- ✅ Extracts top keywords from approved ad copy headlines
- ✅ Graceful fallbacks if data is missing
- ✅ Handles both string and array formats for bidding_strategy JSON

---

## 🔴 HIGH PRIORITY - Brand Guideline Integration

### 4. Integrate Brand Extraction with Website Scraper
**File:** `app/Jobs/ScrapeCustomerWebsite.php`  
**Status:** ✅ **COMPLETE** (Enhanced with Job Batching)

**Implementation:**
- ✅ Integrated with `CrawlSitemap` job batching system
- ✅ `CrawlPage` jobs now use `Batchable` trait
- ✅ `CrawlSitemap` creates batch with completion callback
- ✅ `ExtractBrandGuidelines` only dispatches after ALL pages crawled
- ✅ Batch cancellation check in `CrawlPage` for early termination
- ✅ Created `CrawlCustomerSitemap` artisan command for testing

**Job Batching Flow:**
```
1. CrawlSitemap dispatched with customer sitemap URL
   ↓
2. Parses sitemap XML, creates batch of CrawlPage jobs
   ↓
3. Each CrawlPage job scrapes page → stores in KnowledgeBase
   ↓
4. Batch completion callback triggers ExtractBrandGuidelines
   ↓
5. Brand guidelines extracted from FULL knowledge base content
```

**Testing Command:**
```bash
php artisan sitemap:crawl {customer_id} {sitemap_url}
```

**Impact:** Brand extraction now waits for complete sitemap crawl instead of premature 2-minute delay!

---

## 🟡 MEDIUM PRIORITY - Testing & Validation

### 5. Test Brand Guideline Extraction
**Command:** `php artisan brand:extract <customer_id>`  
**Required:**
- [ ] Extract from 3-5 real customer websites (diverse industries)
- [ ] Verify quality scores > 70
- [ ] Check JSON structure validity
- [ ] Verify color/font extraction accuracy
- [ ] Review brand voice extraction quality  
**Status:** ❌ Not Started

---

### 6. Test Content Generation with Brand Guidelines
**Required:**
- [ ] Generate strategy with brand guidelines
- [ ] Generate ad copy with brand guidelines
- [ ] Generate images with brand guidelines
- [ ] Generate video scripts with brand guidelines
- [ ] Compare output quality: with vs without guidelines  
**Status:** ❌ Not Started

---

### 7. Test End-to-End Flow
**Flow:** Website Scrape → Knowledge Base → Brand Extraction → Content Generation  
**Required:**
- [ ] Trigger website scrape for test customer
- [ ] Verify knowledge base population
- [ ] Verify brand extraction auto-triggers
- [ ] Verify content generation uses guidelines
- [ ] Check logs for warnings/errors  
**Status:** ❌ Not Started

---

## 🟢 LOW PRIORITY - Enhancements

### 8. Enhance Extraction Quality
**Potential Improvements:**
- [ ] Add logo detection and analysis
- [ ] Extract hero section messaging specifically
- [ ] Parse about page for mission/values
- [ ] Extract testimonials for proof points
- [ ] Analyze multiple pages (not just homepage)  
**Status:** ❌ Not Started

---

### 9. Add UI for Brand Guidelines
**Required:**
- [ ] Display brand guidelines on customer dashboard
- [ ] Show quality score and extraction date
- [ ] Add "Refresh Guidelines" button
- [ ] Add manual verification checkbox (user_verified)
- [ ] Show extraction history  
**Status:** ❌ Not Started

---

### 10. Implement Brand Consistency Scoring
**Goal:** Score generated content for brand alignment  
**Required:**
- [ ] Create BrandConsistencyScorer service
- [ ] Check voice/tone alignment
- [ ] Verify color palette usage (images)
- [ ] Validate messaging theme usage
- [ ] Flag "do not use" violations  
**Status:** ❌ Not Started

---

## 📋 COMPLETED TASKS ✅

### Core Brand Guideline System
- [x] Create `brand_guidelines` table migration
- [x] Create `BrandGuideline` model with helper methods
- [x] Create `BrandGuidelineExtractionPrompt` (250+ lines)
- [x] Create `BrandGuidelineExtractorService` (HTML/CSS parsing)
- [x] Create `ExtractBrandGuidelines` queue job
- [x] Create `ExtractBrandGuidelinesCommand` artisan command
- [x] Add `brandGuideline()` relationship to Customer model

### Prompt Integration
- [x] Update `AdCopyPrompt` with brand guidelines
- [x] Update `ImagePrompt` with brand guidelines
- [x] Update `VideoScriptPrompt` with brand guidelines
- [x] Update `StrategyPrompt` with brand guidelines

### Job Integration
- [x] Update `GenerateAdCopy` to fetch/pass brand guidelines
- [x] Update `GenerateImage` to fetch/pass brand guidelines
- [x] Update `GenerateVideo` to fetch/pass brand guidelines
- [x] Update `GenerateStrategy` to fetch/pass brand guidelines

### Bug Fixes
- [x] Fix `GenerateStrategy.php` string concatenation bug (line 71)
- [x] Fix `AdminMonitorService` approval threshold (50 → 75)
- [x] Fix `config/platform_rules.php` Google SEM description length (95 → 90)
- [x] Fix `ApplySeasonalStrategyShift` placeholder data with real database queries

### Infrastructure
- [x] Implement job batching for CrawlSitemap → CrawlPage workflow
- [x] Add batch completion callback for brand extraction trigger
- [x] Create `CrawlCustomerSitemap` artisan command
- [x] Integrate brand extraction with sitemap crawling completion

---

## 🎯 SESSION GOALS

**Today's Target:** Complete all HIGH PRIORITY items (Tasks 1-4)

**Estimated Time:**
- Task 1 (AdminMonitorService): 5 minutes
- Task 2 (platform_rules): 2 minutes
- Task 3 (ApplySeasonalStrategyShift): 15 minutes
- Task 4 (ScrapeCustomerWebsite integration): 5 minutes
- **Total:** ~30 minutes

**Next Session:** Testing (Tasks 5-7)

---

## 📝 NOTES

### Known Limitations
1. Brand extraction only scrapes homepage (not multi-page)
2. Color extraction limited to inline styles and style tags (no external CSS)
3. Manual verification recommended for scores < 70
4. No automatic re-extraction on website changes (yet)

### Dependencies
- Google Gemini 2.5 Pro API (extraction + content generation)
- Spatie Browsershot (JavaScript-rendered website scraping)
- Laravel Queue system (Redis or database driver)
- PostgreSQL/MySQL with JSON column support

### Related Documentation
- `/BRAND_GUIDELINE_INTEGRATION_COMPLETE.md` - Complete implementation summary
- `/BRAND_GUIDELINE_EXTRACTION_IMPLEMENTATION.md` - Original implementation plan
- `docs/System Design/AGENT_PROMPT_REVIEW.md` - Comprehensive agent/prompt review
