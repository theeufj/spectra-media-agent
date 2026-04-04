# Strategic Architecture: Multi-Product B2B AI SaaS

**Structuring, Capital Raising, and Market Synergies — Australia**
*April 2026*

---

## Executive Summary

This document outlines the strategic architecture for bringing two B2B AI SaaS products — **sitetospend** (autonomous advertising) and **Proveably** (security & compliance) — to market under a single umbrella entity headquartered in Sydney.

The core thesis: these products target the **same customer at sequential lifecycle stages**. sitetospend acquires customers during their growth phase; Proveably retains them as they mature into data-secure, compliant organizations. Executing this requires deliberate corporate structuring, IP protection, and precise investor positioning.

---

## 1. Corporate Structure: HoldCo / OpCo Model

### Why Not a Simple Pty Ltd?

A single proprietary limited company is insufficient for a multi-product software enterprise where IP is the primary value driver. The standard for sophisticated Australian startups is the **dual-company structure**.

### The Model

Under the Australian Corporations Act 2001 (Section 46), the structure operates as follows:

**Holding Company (HoldCo)**
- Ultimate parent entity and primary vehicle for investment
- Does not trade, employ staff, or enter customer contracts
- Owns all IP: both codebases, ML algorithms, training datasets, trade marks, domains, cash reserves
- Investors purchase equity exclusively at this level

**Operating Company (OpCo)**
- Wholly owned subsidiary of HoldCo
- Public-facing commercial entity
- Employs team, signs customer agreements, enters vendor contracts
- Absorbs all operational and legal liabilities

**How it connects:** HoldCo grants a formal intercompany IP license to OpCo, permitting the subsidiary to commercialize the software while maintaining strict ownership boundaries.

### Why This Matters

| Consideration | Single Pty Ltd | HoldCo / OpCo |
|---|---|---|
| **IP protection** | Exposed to all commercial risk | Quarantined in non-trading HoldCo |
| **Liability containment** | Single lawsuit can jeopardize everything | Operational risk contained in OpCo |
| **VC appeal** | Often requires costly restructuring pre-Series A | Preferred by institutional investors |
| **Strategic flexibility** | Hard to spin off or sell product lines | New OpCos for products, markets, or M&A |
| **Admin overhead** | Lower setup costs | Higher: dual ASIC registration, separate accounts, intercompany agreements |

### Action Required

- Establish HoldCo and OpCo structure before any capital raise
- Restructuring later is painful, expensive, and introduces delays during due diligence

---

## 2. Intellectual Property: Chain of Title

IP is the primary driver of enterprise valuation. Investors demand an **unbroken, documented chain of title** proving the HoldCo owns all technology assets. Gaps here routinely delay capital raises and incur legal remediation costs.

### Three Critical Vulnerability Areas

**Pre-Incorporation Founder Work**
- Any code, architecture, algorithms, or brand assets created before incorporation remain the personal property of the individual founder
- **Required:** Deed of IP Assignment transferring all historical and future rights to HoldCo

**Independent Contractors**
- Unlike employees (where IP generally vests with employer), contractors retain copyright unless there's an express written assignment
- **Required:** Contractor agreements with robust IP assignment and moral rights waiver clauses, executed before work begins
- Risk: "IP ransom" during a funding round if a former contractor asserts ownership over a critical module

**Brand and Trade Mark Registration**
- Unregistered common law rights provide minimal protection
- **Required:** Trade mark registration with IP Australia for the umbrella brand, Proveably, and sitetospend
- Investors treat registered marks as economic moats securing exclusive market rights

| IP Origin | Default Legal Position | Required Action |
|---|---|---|
| Pre-incorporation founder work | Owned by individual | Deed of IP Assignment to HoldCo |
| Full-time employees | Generally vests with employer | Explicit IP clauses in employment contracts |
| Contractors / agencies | Retained by contractor | Written IP assignment + moral rights waiver before work starts |
| Brand assets / code | Unregistered, weak protection | Trade mark registration with IP Australia |

---

## 3. R&D Tax Incentive (R&DTI)

### The Opportunity

The R&DTI provides a **43.5% refundable tax offset** for eligible entities with under $20M aggregated turnover. This is essentially free runway — a direct cash refund subsidizing nearly half of eligible R&D costs.

### What Qualifies

Both products have strong R&DTI-eligible activities:

**sitetospend:**
- Autonomous AI agent architecture (self-healing, budget intelligence, creative testing)
- Vision AI brand extraction from website screenshots
- Multi-platform campaign orchestration algorithms
- Competitive intelligence discovery and analysis agents

**Proveably:**
- AI-powered security scanning engine
- Automated compliance mapping (SOC 2 / ISO 27001)
- AI triage and remediation of vulnerabilities
- Scanning of AI-generated code patterns

### Structural Considerations

If HoldCo and OpCo form a consolidated group for income tax purposes:
- Only the head company (HoldCo) registers R&D activities and claims the offset
- Activities across the group are treated as if conducted by a single entity
- Engineering work must be documented proving: novel technical knowledge, specific technical uncertainties, systematic experimental progression

### Action Required

- Register eligible R&D activities with the ATO / Department of Industry
- Maintain rigorous documentation of experimental work
- Claim the offset — this is non-dilutive capital that extends runway significantly

---

## 4. The Lifecycle Thesis: Growth → Maturity

### The Core Insight

sitetospend and Proveably don't share a codebase. They share a **customer lifecycle**.

**Phase 1 — Growth (sitetospend):**
A startup launches and needs initial traction. They allocate budget toward advertising but lack the expertise or budget for a traditional agency. sitetospend's AI agents autonomously manage campaigns across Google and Meta, optimizing budgets and generating creatives, driving initial user acquisition.

**Phase 2 — Maturation (Proveably):**
Because sitetospend was successful, the startup now has real customers, revenue, and sensitive data. They face a new problem: how do they ensure their application is secure? How do they prove compliance to enterprise clients? They transition to Proveably, which scans their infrastructure, fixes security gaps, and automates SOC 2 / ISO 27001 compliance.

The umbrella brand captures the customer at inception (demand generation) and scales with them to the mid-market (enterprise security and compliance).

### Impact on Unit Economics

| Metric | Definition | Impact of Lifecycle Strategy |
|---|---|---|
| **CAC Payback** | Months to recoup acquisition cost | Accelerated — selling Proveably to an existing sitetospend user has near-zero marginal acquisition cost |
| **Net Revenue Retention** | Revenue retained from existing customers including expansion | Enhanced — cross-sell drives expansion revenue, offsetting churn at the lower end |
| **LTV:CAC Ratio** | Lifetime value vs. acquisition cost | Increased — LTV expands as customers graduate between products, blended CAC decreases |

### Honest Assessment of This Thesis

**What's strong:**
- The narrative directly addresses the "lack of focus" objection VCs raise about multi-product companies at seed
- The lifecycle model is logical and resonates with investors who care about NRR and LTV:CAC
- If proven, the compounding economics are genuinely powerful

**Where it's vulnerable:**

- **The conversion assumption is unproven.** A vibe-coder who needs ad management isn't guaranteed to need SOC 2 compliance. "I need ads" to "I need security auditing" is a big leap. Many will churn before reaching the maturity stage. This is presented as inevitable in strategic documents but needs real data to support it.

- **"Near-zero marginal CAC" is optimistic.** Selling security/compliance to a marketing customer requires educating a different buying persona (CTO vs. growth lead). It's cheaper than cold outbound, but it's not free. Two distinct sales motions still exist.

- **The cohorts may overlap but aren't identical.** Founders who need ad management and founders who hit compliance walls may be different segments of the same broad market. The linear journey rarely plays out as cleanly as the thesis suggests.

**What would make it credible:** Even 3–5 documented cases of a sitetospend customer later adopting Proveably (or expressing the need unprompted) would transform this from strategy to proof.

---

## 5. Brand Architecture: The Umbrella Strategy

### How It Works

A single parent brand markets both products. Customers recognize the unifying corporate entity behind both the growth solution (sitetospend) and the security solution (Proveably).

### Strategic Benefits

**Cost efficiency in customer acquisition:**
Marketing two independent brands requires separate budgets, isolated SEO strategies, distinct social media, and parallel PR. An umbrella brand consolidates resources into a single authoritative domain and unified market presence.

**Brand equity transfer:**
If a startup trusts the parent brand to manage their ad spend via sitetospend, they have a pre-established disposition to trust the same brand's security product. This reduces friction in cross-selling as needs mature.

**Bundling opportunities:**
Rather than two separate procurement cycles, a bundled offering presents a streamlined vendor relationship. sitetospend serves as a high-velocity, low-friction acquisition wedge; Proveably is the heavier, higher-value cross-sell once customers reach scale.

### Honest Assessment

- **B2B buyers purchase products, not brands.** The "halo effect" and "brand equity transfer" arguments are stronger in consumer psychology than enterprise buying. Nobody picks a security scanner because they like the parent brand's ad tool. The cross-sell math works, but it works because of trust in the vendor relationship — not brand recognition.

- **The umbrella adds real value in cost consolidation** — one domain, one content engine, one brand presence. This is the practical win, not the psychological one.

---

## 6. Investor Positioning in 2026

### The Australian VC Landscape

The market is defined by:
- **Capital abundance with deployment caution** — top firms (Blackbird, AirTree, Square Peg) have large reserves but are writing fewer, higher-conviction checks
- **Flight to quality** — consolidating capital into fewer bets
- **AI premium** — startups with genuine AI-native architectures command massive valuation premiums

### The Narrative Challenge

Pitching two products at Seed directly contradicts the VC heuristic that early-stage startups must focus on a single wedge. The pitch must dismantle this skepticism.

### Three Strategic Pillars for the Pitch

**1. Owning the Growth-to-Maturity Pipeline**
Vibe-coders build fast, use sitetospend to grow, then immediately hit a compliance wall. Proveably catches them. The umbrella brand isn't building disconnected tools — it's building a net to capture and monetize the fastest-growing segment of the modern economy.

**2. Structural Advantage in Customer Acquisition**
Mathematically demonstrate that the multi-product strategy is a customer acquisition mechanism. The initial ad product creates a zero-CAC pipeline for the high-ticket security product, altering unit economics and driving rapid payback periods.

**3. Capital Efficiency via AI Agents**
AI agents for both marketing optimization and security remediation allow the OpCo to service a massive volume of SMBs without scaling headcount linearly — achieving the "Burn Multiples" required by 2026 investors.

### Honest Assessment of VC Risk

**Two products at Seed is genuinely hard to pitch.**
The strategy document acknowledges the "lack of focus" risk but underestimates how deeply Australian VCs believe in single-product execution. Blackbird and AirTree will push back hard.

**Recommended approach:** Pitch Proveably as the primary bet (stronger defensibility, clearer wedge, more obvious PLG motion). Position sitetospend as the acquisition wedge / lead-gen engine, not a co-equal product. This preserves the lifecycle narrative while respecting the focus mandate.

**The competitive landscape for Proveably is tough.**
Vanta raised at $2.45B. Drata at $2B. Both are moving downmarket. The "priced for indie devs / AI-generated code" angle is strong, but the window to capture this niche before incumbents add a "paste URL" feature is narrow. This needs to be addressed head-on, not ignored.

**The "vibe coder" market sizing is unproven.**
Jumping from "$266B cybersecurity market" to "AI-built web security" without sizing the actual niche will get probed. How many vibe-coded apps exist? What's their security spend? TAM/SAM/SOM needs bottom-up validation.

**What's missing from any pitch: real numbers.**
AU VCs in 2026 want evidence of product-market fit. MRR, sign-up volume, scan counts, conversion rates, churn. Even "50 free users, 3 paying" is better than a pure strategy document. The strategic framing is table stakes — the numbers close.

---

## 7. Immediate Action Items

### Non-Negotiable (Do Now)

| Action | Why | Effort |
|---|---|---|
| **Establish HoldCo/OpCo structure** | Protects IP, satisfies VC due diligence, enables clean cap table | Medium — lawyer + ASIC registration |
| **Execute Deed of IP Assignment** | All pre-incorporation code must be formally assigned to HoldCo | Low — legal template + execution |
| **Contractor IP audit** | Ensure all contractor work has written IP assignment | Low–Medium — review all historical agreements |
| **Trade mark registration** | File with IP Australia for umbrella brand, Proveably, sitetospend | Low — ~$250 per mark |
| **R&DTI registration** | Register eligible activities before end of FY | Medium — document experimental work, file with ATO |

### Before Any Capital Raise

| Action | Why |
|---|---|
| **Compile traction metrics** | MRR, customer count, growth rate, retention, ad spend under management |
| **Document 3–5 lifecycle conversion examples** | Prove the sitetospend → Proveably journey actually happens |
| **Size the vibe-coder market bottom-up** | Number of AI-generated apps, average security spend, conversion assumptions |
| **Prepare competitive positioning for Proveably** | Head-on comparison with Vanta/Drata, emphasizing the niche they can't serve |

### Strategic Recommendations

1. **Lead investor conversations with Proveably.** It has a clearer wedge (AI-built apps need security), stronger defensibility (20+ scanners + compliance mapping), and a more obvious PLG motion. Present sitetospend as the growth engine, not the headline product.

2. **Claim the R&DTI immediately.** Both products have substantial eligible R&D. At 43.5% refundable offset, this is potentially $200K+ per year returned as cash.

3. **Don't send the strategic architecture document to investors.** It reads like a strategy consultant's output. Use it internally for structuring decisions. The pitch deck should lead with product, traction, and the lifecycle narrative in 3 paragraphs — not corporate law.

4. **Build the cross-sell proof.** Even a handful of documented cases where an sitetospend customer expressed security/compliance needs would transform the lifecycle thesis from theory to evidence.

---

## References

1. *A Study of Umbrella Branding Strategies Used by Selected Companies for the Promotion of Related Products* — IJSTM
2. *Winning Strategies of Hypergrowth SaaS Champions* — Boston Consulting Group
3. *Winning the SMB Tech Market in a Challenging Economy* — McKinsey
