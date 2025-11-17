# GTM Implementation - Master Todo List

## PHASE 0: GTM Detection (Critical Foundation - Must Do First!)

- [ ] Create `GTMDetectionService` class with regex pattern matching for GTM container IDs
- [ ] Add detection integration into existing `ScrapeCustomerWebsite` job
- [ ] Add `gtm_detected` and `gtm_detected_at` columns to customers table migration
- [ ] Test GTM detection on 5+ real customer websites
- [ ] Update GTM setup controller to use detection result automatically
- [ ] Verify automatic Path A/B routing works correctly

---

## WEEK 1: GTMContainerService Implementation

- [ ] Set up Google Tag Manager API client initialization
- [ ] Implement `linkExistingContainer($customer, $containerId)` method
  - [ ] Verify container exists via GTM API
  - [ ] Verify we have access permissions
  - [ ] Store container ID in database
  - [ ] Return container details
- [ ] Implement `addConversionTag($customer, $tagName, $conversionId)` method
  - [ ] Create tag config with conversion ID and label
  - [ ] Call GTM API to create tag
  - [ ] Log success with tag ID
  - [ ] Return tag ID
- [ ] Implement `addTrigger($customer, $triggerName, $triggerType, $config)` method
  - [ ] Support page view triggers
  - [ ] Support purchase/transaction triggers
  - [ ] Support form submission triggers
  - [ ] Support custom event triggers
  - [ ] Support scroll depth triggers
  - [ ] Call GTM API to create trigger
  - [ ] Return trigger ID
- [ ] Implement `publishContainer($customer, $notes = '')` method
  - [ ] Create new workspace version
  - [ ] Call GTM API publish
  - [ ] Handle tags going live on customer's website
  - [ ] Log version ID and timestamp
  - [ ] Return publication status
- [ ] Add comprehensive logging to all methods
- [ ] Add error handling for all edge cases
- [ ] Create unit tests for all methods

---

## WEEK 2: UI & Beta Testing

- [ ] Create database migrations for GTM columns:
  - [ ] `gtm_container_id`
  - [ ] `gtm_account_id`
  - [ ] `gtm_workspace_id`
  - [ ] `gtm_config` (JSON)
  - [ ] `gtm_installed` (boolean)
  - [ ] `gtm_last_verified` (timestamp)
- [ ] Create GTM setup form/page in customer dashboard
- [ ] Build conditional logic: "Do you have GTM?" branching
- [ ] Create container ID input form with validation
- [ ] Build success/error messaging and status display
- [ ] Create troubleshooting guide for customers
- [ ] Write customer setup documentation
- [ ] Brief support team on new GTM feature
- [ ] Identify 1-2 beta customers for testing
- [ ] Test complete flow with beta customer(s)
- [ ] Verify all tags fire on customer website
- [ ] Collect feedback from beta customers
- [ ] Fix any issues found during testing

---

## WEEK 3: Tag Generation Services

- [ ] Build `ConversionTagGenerator` service
  - [ ] `generateConversionTag($config)` - Google Ads conversion tags
  - [ ] `generateFacebookPixelTag($pixelId)` - Facebook pixel tags
  - [ ] `generateGA4EventTag($measurementId)` - Google Analytics 4 tags
- [ ] Build `TriggerGenerator` service
  - [ ] `generatePageViewTrigger()` - all pages or specific URL
  - [ ] `generatePurchaseTrigger()` - for ecommerce tracking
  - [ ] `generateFormSubmitTrigger($selector)` - form submissions
  - [ ] `generateCustomEventTrigger($eventName)` - custom events
  - [ ] `generateScrollDepthTrigger()` - scroll tracking
- [ ] Implement automatic tag generation based on customer platforms
- [ ] Test tag generation with beta customer
- [ ] Verify generated tags format correctly for GTM API
- [ ] Create unit tests for generators

---

## WEEK 4: End-to-End & Launch

- [ ] Run full end-to-end testing:
  - [ ] Customer provides container ID
  - [ ] Service links container
  - [ ] Service adds conversion tags
  - [ ] Service creates triggers
  - [ ] Service publishes changes
  - [ ] Tags fire on customer website
- [ ] Verify all tags firing correctly
- [ ] Verify conversion tracking accuracy
- [ ] Test with multiple customers simultaneously
- [ ] Create production deployment checklist
- [ ] Set up monitoring and alerting for GTM operations
- [ ] Verify database migrations run successfully
- [ ] Deploy to production
- [ ] Monitor for issues post-launch
- [ ] Create troubleshooting runbook for support

---

## MONTH 2: Path B - New GTM Containers (For customers without GTM)

- [ ] Implement container creation for customers without GTM
- [ ] Build GTM install snippet generation
- [ ] Create WordPress plugin for WordPress sites (optional)
- [ ] Build installation verification system
- [ ] Create UI for "Create New Container" path
- [ ] Test Path B with customers who don't have GTM
- [ ] Launch Path B to all customers

---

## Post-Launch: Monitoring & Optimization (Q2 2026)

- [ ] Build tag health monitoring system
- [ ] Implement alerting for failed tags
- [ ] Create tag performance dashboard
- [ ] Build customer-facing troubleshooting guides
- [ ] Implement server-side tagging option (for privacy)
- [ ] Add enhanced ecommerce tracking support
- [ ] Build cross-domain tracking support
- [ ] Create event debugging UI

---

## Testing Checklist

### Unit Tests

- [ ] Container linking verification
- [ ] Tag generation validation
- [ ] Trigger creation validation
- [ ] Publishing success confirmation
- [ ] Error handling for all methods
- [ ] Logging verification

### Integration Tests

- [ ] Full flow: Customer ID → Tags live
- [ ] Tag firing on customer website
- [ ] Conversion tracking accuracy
- [ ] Multiple customers simultaneously
- [ ] Database migrations

### Beta Testing (Week 2)

- [ ] Test with 1-2 real beta customers
- [ ] Verify all tags fire correctly
- [ ] Collect customer feedback
- [ ] Document any issues found
- [ ] Get sign-off before production launch

---

## Pre-Launch Verification

- [ ] All unit tests passing
- [ ] All integration tests passing
- [ ] Beta testing complete and documented
- [ ] All issues resolved
- [ ] Production API credentials configured
- [ ] Monitoring and alerting set up
- [ ] Database migrations tested and ready
- [ ] Support team trained
- [ ] Customer documentation complete
- [ ] Deployment checklist complete

---

## Success Criteria

- [ ] Setup time < 5 minutes for Path A
- [ ] Setup time < 10 minutes for Path B
- [ ] > 99% tag firing rate
- [ ] > 80% adoption rate within 2 weeks
- [ ] Conversions appear in Google Ads within 24 hours
- [ ] Zero support complaints about setup
- [ ] All tags verified firing on production

---

## Notes

- **CRITICAL:** Complete PHASE 0 (GTM Detection) BEFORE Week 1 - this is the foundation
- Start Week 1 immediately after Phase 0
- Beta testing is essential - do not skip Week 2
- Path B (new containers) is secondary - focus Phase 1 on Path A (existing GTM)
- Keep detailed logs of all operations for troubleshooting
