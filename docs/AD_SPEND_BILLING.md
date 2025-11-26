# Ad Spend Billing System

## Overview

SiteToSpend uses a prepaid credit system for ad spend billing. This protects both you and us from billing issues while ensuring your campaigns run smoothly.

## How It Works

### 1. Initial Credit (Campaign Deployment)

When you deploy your first campaign, we charge **7 days of estimated ad spend** upfront to your credit balance.

**Example:**
- Daily budget: $50/day
- Initial charge: $50 × 7 = **$350**

This credit is then used to pay for your actual daily ad spend.

### 2. Daily Billing

Every day at 6:00 AM, we:

1. Calculate your **actual ad spend** from the previous day (from Google/Facebook APIs)
2. Deduct that amount from your credit balance
3. If your balance drops below 3 days of average spend, we automatically replenish it

**You're always billed for actual spend, not estimates.** The initial credit just ensures we have funds available.

### 3. Auto-Replenishment

When your credit balance falls below 3 days of your average daily spend, we automatically charge your card to replenish it back to 7 days.

**Example:**
- Average daily spend: $75/day
- Balance drops to: $150 (2 days remaining)
- Auto-charge: $525 (7 days × $75)
- New balance: $675

### 4. What Happens If Payment Fails?

We have a graduated response to protect your campaigns while giving you time to fix payment issues:

| Timeline | What Happens | Action Required |
|----------|--------------|-----------------|
| **Immediately** | Payment retry + warning email | Update payment method |
| **After 24 hours** | Budget reduced to 50% + failure email | Urgent: fix payment |
| **After 48 hours** | All campaigns paused + pause email | Add payment to resume |
| **Payment Fixed** | Campaigns auto-resume within 1 hour | None |

### 5. Managing Your Ad Spend Credit

Visit **Settings → Ad Spend Billing** to:

- View your current credit balance
- See transaction history
- Add credit manually
- Retry failed payments

---

## Frequently Asked Questions

### Does my subscription include ad spend?

**No.** Your monthly subscription ($99-$499) covers the SiteToSpend platform, AI agents, and campaign management. Ad spend is billed separately and paid directly to Google/Facebook through us.

### Why do you charge upfront?

When your ads run, Google and Facebook charge us immediately. The prepaid credit ensures we can cover your ad spend without risk of non-payment, which would force us to pause your campaigns.

### How is actual spend calculated?

We pull spend data directly from Google Ads and Facebook Ads APIs every morning. You're only charged for actual spend—never estimates.

### What if I overpay?

Your credit balance rolls over. If your campaigns are paused or you reduce budgets, your existing credit remains available for future use.

### Can I get a refund of unused credit?

Yes. If you cancel your account, any remaining ad spend credit (minus pending charges) will be refunded to your original payment method within 5-7 business days.

### What payment methods do you accept?

We accept all major credit and debit cards through Stripe:
- Visa
- Mastercard
- American Express
- Discover

### Why was my payment declined?

Common reasons include:
- Insufficient funds
- Expired card
- Bank fraud protection triggered
- Card spending limit reached

Contact your bank if the issue persists, or try a different card.

### What happens to my campaigns if payment fails?

1. **First 24 hours:** Campaigns continue running at full budget while you fix payment
2. **24-48 hours:** Campaigns run at 50% budget
3. **After 48 hours:** Campaigns are paused to prevent further charges

Once payment is fixed, campaigns resume automatically.

### Can I set spending limits?

Yes. When creating a campaign, you set a daily budget. We also support:
- **Campaign-level caps:** Maximum spend per campaign
- **Account-level caps:** Maximum total ad spend across all campaigns

### How do I add more credit?

Go to **Settings → Ad Spend Billing → Add Credit** and enter the amount you want to add. Minimum top-up is $50.

---

## Technical Details

### Billing Schedule

| Job | Frequency | Time |
|-----|-----------|------|
| Daily ad spend billing | Daily | 6:00 AM UTC |
| Payment retry (if failed) | Daily | 6:00 AM UTC |
| Low balance check | Daily | 6:00 AM UTC |

### Credit Status Types

| Status | Meaning |
|--------|---------|
| `active` | Account in good standing |
| `low_balance` | Less than 3 days of spend remaining |
| `depleted` | Credit exhausted, needs replenishment |
| `suspended` | Account suspended (contact support) |

### Payment Status Types

| Status | Meaning |
|--------|---------|
| `current` | All payments up to date |
| `grace_period` | Payment failed, 24-hour grace period active |
| `failed` | Payment failed twice, budgets reduced |
| `paused` | Payment failed 3+ times, campaigns paused |

---

## API Endpoints

For developers integrating with our billing system:

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/billing/ad-spend` | GET | Billing dashboard (web) |
| `/billing/ad-spend/balance` | GET | Get current balance |
| `/billing/ad-spend/transactions` | GET | Get transaction history |
| `/billing/ad-spend/add-credit` | POST | Add credit manually |
| `/billing/ad-spend/retry-payment` | POST | Retry failed payment |

---

## Contact

If you have billing questions or issues:

- **Email:** billing@sitetospend.com
- **Dashboard:** Settings → Ad Spend Billing
- **Live Chat:** Available during business hours
