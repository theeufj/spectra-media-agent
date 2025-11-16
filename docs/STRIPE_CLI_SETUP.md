# Stripe CLI Setup for Hybrid Billing

This document provides the Stripe CLI commands to create the product and prices required for the hybrid billing model.

---

## Instructions

Run these commands in your terminal. Make sure you have the [Stripe CLI](https://stripe.com/docs/stripe-cli) installed and are logged in (`stripe login`).

### 1. Create the Product

This command creates the core "Spectra Subscription" product.

```bash
stripe products create --name "Spectra Subscription"
```

After running this, the CLI will return a JSON object. **Copy the `id` from the response (it will look like `prod_xxxxxxxxxxxx`)**. You'll need it for the next steps.

### 2. Create the Recurring Subscription Price

This creates the fixed $200/month price. Replace `prod_xxxxxxxxxxxx` with the product ID you copied from the previous step.

```bash
stripe prices create \
  --product "prod_xxxxxxxxxxxx" \
  --unit-amount 20000 \
  --currency usd \
  --recurring-interval month
```

The CLI will return a JSON object for the price. **Copy the `id` from this response (it will look like `price_xxxxxxxxxxxx`)**. This is your `STRIPE_SUBSCRIPTION_PRICE_ID`.

### 3. Create the Metered Ad Spend Price

This creates the metered price for tracking ad spend. Replace `prod_xxxxxxxxxxxx` with the same product ID from step 1.

```bash
stripe prices create \
  --product "prod_xxxxxxxxxxxx" \
  --unit-amount 1 \
  --currency usd \
  --billing-scheme per_unit \
  --recurring-usage-type metered
```

The CLI will return another price object. **Copy the `id` from this response**. This is your `STRIPE_AD_SPEND_PRICE_ID`.

### 4. Update Your `.env` File

Finally, add the two price IDs you copied to your `.env` file:

```env
STRIPE_SUBSCRIPTION_PRICE_ID=price_xxxxxxxxxxxx
STRIPE_AD_SPEND_PRICE_ID=price_yyyyyyyyyyyy
```

After completing these steps, your Stripe account will be fully configured to handle the hybrid billing model.
