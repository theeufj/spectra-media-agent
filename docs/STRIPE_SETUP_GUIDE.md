# Stripe Setup Guide for Hybrid Billing

To support the $200/month subscription plus daily metered ad spend, you need to configure your Stripe account correctly. This guide will walk you through creating the required product and its two associated prices.

---

## 1. Create the Core Product

First, create a single product that represents your service.

1. Navigate to the **Products** section in your Stripe Dashboard.
2. Click **+ Add product**.
3. **Name**: Enter `Spectra Subscription`.
4. **Description**: (Optional) Add a description like "Access to the Spectra AI marketing platform."
5. Click **Save product**.

---

## 2. Create the Recurring Subscription Price

This is the fixed $200/month base fee.

1. On the product page for "Spectra Subscription", click **+ Add another price**.
2. **Pricing model**: Select **Standard pricing**.
3. **Price**: Enter `200`.
4. **Currency**: Select `USD` (or your currency).
5. **Billing period**: Select **Monthly**.
6. Click **Save price**.
7. After saving, copy the **API ID** for this price (e.g., `price_xxxxxxxxxxxx`). You will need to add this to your `.env` file as `STRIPE_SUBSCRIPTION_PRICE_ID`.

---

## 3. Create the Metered Usage Price

This price will track the daily ad spend.

1. On the same product page, click **+ Add another price** again.
2. **Pricing model**: Select **Metered usage**. This is the key step.
3. **How usage is reported**: Choose **By summing up usage for a billing period**.
4. **Price**: Enter `1`.
5. **Currency**: Select `USD` (or your currency).
6. **Billing period**: Select **Monthly**. Stripe will accumulate the daily reported usage and bill for it at the end of the monthly cycle along with the subscription fee.
7. Under "Advanced options", find the **API ID** for this price (it's visible before saving). Copy it. You will need this for your `.env` file as `STRIPE_AD_SPEND_PRICE_ID`.
8. Click **Save price**.

---

## 4. Update Your `.env` File

Once you have created the product and both prices, add the Price IDs to your `.env` file:

```env
STRIPE_SUBSCRIPTION_PRICE_ID=price_xxxxxxxxxxxx
STRIPE_AD_SPEND_PRICE_ID=price_yyyyyyyyyyyy
```

With this Stripe configuration, your application will be able to subscribe users to the base plan and report their ad spend usage daily.
