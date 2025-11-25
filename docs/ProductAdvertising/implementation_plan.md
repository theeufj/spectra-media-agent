# Product & Money Page Advertising Feature

## Overview
This feature allows users to specifically target high-value "Product" or "Money" pages when creating ad campaigns. Instead of generic ad copy, the system will use the specific content (price, description, images) of the selected pages to generate highly relevant ads.

## Backend Implementation (Completed)

### 1. Data Modeling (`CustomerPage`)
We created a dedicated `CustomerPage` model to store structured data about customer pages, distinct from the general `KnowledgeBase`.
- **Table:** `customer_pages`
- **Key Fields:**
    - `page_type`: Enum (`product`, `money`, `general`).
    - `metadata`: JSON column for extracted data (Price, SKU, Image URL, Schema.org data).
    - `embedding`: Vector embedding of the page summary for semantic matching.
    - `content`: Cleaned text content.

### 2. Intelligent Crawler (`CrawlPage` Job)
The crawler was updated to automatically classify pages during the scraping process.
- **Detection Logic:**
    - **Schema.org:** Checks for `application/ld+json` with `@type: Product`.
    - **Heuristics:** Scans HTML for "Add to Cart", "Buy Now", and price patterns (e.g., `$XX.XX`).
- **Storage:** If a page is identified as belonging to the customer, it is saved to `CustomerPage` instead of the generic knowledge base.

### 3. API Layer
- **Endpoint:** `GET /api/customers/{customer}/pages`
- **Filtering:** Supports `?type=product` to fetch only product pages.
- **Search:** Supports `?search=query` to find pages by title or URL.

## Frontend Implementation Plan (Next Steps)

### 1. Campaign Creation Wizard
- **Location:** `resources/js/Pages/Campaigns/Create.vue` (or similar).
- **New Step:** Add a "Select Products" step after the initial campaign details or strategy selection.
- **UI Components:**
    - A searchable list/grid of detected product pages.
    - Checkboxes to select multiple pages.
    - "Select All" option.

### 2. Data Handling
- Fetch product pages from the new API endpoint when the wizard loads or reaches the step.
- Store selected `page_ids` in the campaign form data.

### 3. Submission
- Send the selected `page_ids` to the backend when creating the campaign.

## Future Work
- **Pivot Table:** Create `campaign_pages` to link `Campaign` and `CustomerPage`.
- **Ad Generation:** Update the LLM prompts to inject the specific metadata (Price, Title) of the selected pages into the generated ad copy.
