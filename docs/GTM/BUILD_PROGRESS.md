# GTM Implementation - Build Progress

## ✅ Completed (Phase 0 & Foundation)

### Phase 0: GTM Detection

- ✅ **GTMDetectionService** (`app/Services/GTM/GTMDetectionService.php`)
  - Regex pattern matching for GTM container IDs
  - Extraction of GTM scripts from HTML
  - Detection metadata generation
  - Validation of container ID format

- ✅ **ScrapeCustomerWebsite Job** (`app/Jobs/ScrapeCustomerWebsite.php`)
  - Fetches website HTML content
  - Integrates GTMDetectionService
  - Updates customer with detection results
  - Logs Path A/B routing automatically

### Database & Models

- ✅ **Migration** (`database/migrations/2025_11_17_140000_add_gtm_columns_to_customers_table.php`)
  - Added 8 new columns for GTM tracking
  - Includes: container ID, account ID, workspace ID, config, installed flag, verification timestamp, detection flag, detection timestamp

- ✅ **Customer Model** (`app/Models/Customer.php`)
  - Added GTM fields to $fillable array
  - Added $casts for proper type conversion (JSON, boolean, datetime)

### Week 1: Core Services

- ✅ **GTMContainerService** (`app/Services/GTM/GTMContainerService.php`)
  - `linkExistingContainer()` - Link customer's GTM container
  - `addConversionTag()` - Add conversion tracking tags
  - `addTrigger()` - Create event triggers
  - `publishContainer()` - Publish changes to production
  - `verifyContainerAccess()` - Verify container permissions
  - Comprehensive logging throughout

- ✅ **ConversionTagGenerator** (`app/Services/GTM/ConversionTagGenerator.php`)
  - `generateConversionTag()` - Google Ads conversion tags
  - `generateFacebookPixelTag()` - Facebook pixel tags
  - `generateGA4EventTag()` - Google Analytics 4 tags
  - `generateAutoSetupConfiguration()` - Multi-platform setup
  - Proper GTM tag configuration format

- ✅ **TriggerGenerator** (`app/Services/GTM/TriggerGenerator.php`)
  - `generatePageViewTrigger()` - All pages or specific URLs
  - `generatePurchaseTrigger()` - Purchase/transaction events
  - `generateFormSubmitTrigger()` - Form submissions
  - `generateCustomEventTrigger()` - Custom DataLayer events
  - `generateScrollDepthTrigger()` - Scroll depth tracking
  - `generateClickTrigger()` - Element click tracking
  - `generateAutoSetupTriggers()` - Multiple trigger setup

### Week 2: Controller & UI Foundation

- ✅ **GTMSetupController** (`app/Http/Controllers/GTMSetupController.php`)
  - `show()` - Display appropriate path (A or B)
  - `linkExistingContainer()` - Handle Path A setup
  - `createNewContainer()` - Path B placeholder
  - `getStatus()` - Get current GTM status
  - `verifyAccess()` - Verify container connectivity
  - `rescan()` - Re-scan website for GTM
  - Input validation and error handling

---

## 🚀 Next Steps (Week 2 - UI & Testing)

### High Priority

1. **Create Routes** - Add routes for GTM setup endpoints in `routes/api.php`
   - `POST /api/customers/{customer}/gtm/setup` - Show setup page
   - `POST /api/customers/{customer}/gtm/link` - Link existing container
   - `POST /api/customers/{customer}/gtm/verify` - Verify access
   - `GET /api/customers/{customer}/gtm/status` - Get status
   - `POST /api/customers/{customer}/gtm/rescan` - Re-scan website

2. **Run Database Migration**

```bash
php artisan migrate
```

3. **Test GTMDetectionService** - Test regex detection on real websites
   - Create unit tests in `tests/Unit/Services/GTM/`
   - Test with known GTM container IDs
   - Test edge cases

4. **Test GTMContainerService** - Verify method signatures and responses
   - Create tests for all public methods
   - Mock GTM API responses

5. **Test Generators** - Verify tag and trigger generation
   - Test all generator methods produce valid GTM configuration
   - Compare output format with GTM API specifications

### Medium Priority

1. **Implement Google OAuth Integration**
   - Store GTM API access tokens for customers
   - Refresh tokens when needed
   - Handle authorization flow

2. **Create Frontend Components**
   - GTM setup form component
   - Container ID input with validation
   - Status display component
   - Loading/success/error states

3. **Write Customer Documentation**
   - How to find your GTM Container ID
   - Step-by-step setup guide
   - Troubleshooting guide

### Testing Strategy

- **Unit Tests**: Test each service method independently
- **Integration Tests**: Test full flow (scrape → detect → link → verify)
- **Beta Testing**: Test with 1-2 real beta customers

---

## 📋 Architecture Overview

### GTM Detection Flow

```
ScrapeCustomerWebsite Job
    ↓
Fetch HTML from customer website
    ↓
GTMDetectionService.getDetectionMetadata()
    ↓
Regex pattern matching for GTM container
    ↓
Update customer: gtm_detected, gtm_container_id, gtm_detected_at
    ↓
Automatic Path A (detected) or Path B (not detected) routing
```

### GTM Setup Flow - Path A (Existing GTM)

```
Customer sees GTM setup page
    ↓
GTMSetupController.show() → Detects Path A
    ↓
Shows form: "Provide your GTM Container ID"
    ↓
Customer enters: GTM-XXXXXX
    ↓
GTMSetupController.linkExistingContainer()
    ↓
GTMContainerService.linkExistingContainer()
    ↓
Verify & store container in database
    ↓
Next: Add conversion tags
```

### Tag Generation Flow

```
Customer authorizes conversion tracking platforms (Google Ads, Facebook, GA4)
    ↓
ConversionTagGenerator generates tag configurations
    ↓
TriggerGenerator generates trigger configurations
    ↓
GTMContainerService.addConversionTag() - Add each tag
    ↓
GTMContainerService.addTrigger() - Add each trigger
    ↓
GTMContainerService.publishContainer() - Publish all changes
    ↓
Tags go LIVE on customer's website
```

---

## 🔧 Key Implementation Notes

### API Integration Points (TODO)
The following require actual Google Tag Manager API implementation:

1. **In GTMContainerService**:
   - `linkExistingContainer()` - Verify container exists via API
   - `addConversionTag()` - Call GTM API to create tag
   - `addTrigger()` - Call GTM API to create trigger
   - `publishContainer()` - Call GTM API to publish
   - `getAccessToken()` - Get/refresh OAuth token

2. **Required Credentials**:
   - Google OAuth access token for GTM API
   - GTM Account ID & Workspace ID
   - Customer's GTM Container ID

### Database Query Examples

```php
// Find customers with GTM detected
$withGTM = Customer::where('gtm_detected', true)->get();

// Find customers who have linked GTM
$withLinkedGTM = Customer::where('gtm_installed', true)->get();

// Find customers ready for conversion tracking
$readyForSetup = Customer::where('gtm_detected', true)
    ->where('gtm_installed', false)
    ->get();
```

### Service Usage Examples

```php
// Detect GTM on website
$detector = new GTMDetectionService();
$containerId = $detector->detectGTMContainer($htmlContent);

// Link existing container
$gtmService = new GTMContainerService();
$result = $gtmService->linkExistingContainer($customer, 'GTM-ABCD1234');

// Generate conversion tags
$generator = new ConversionTagGenerator();
$tagConfig = $generator->generateConversionTag([
    'conversion_id' => '1234567890',
    'conversion_label' => 'AW-12345/AbCdEfGhIjKlMnOpQrSt',
]);

// Generate triggers
$triggerGen = new TriggerGenerator();
$pageViewTrigger = $triggerGen->generatePageViewTrigger([
    'name' => 'Purchase Confirmation Page',
    'url_match_type' => 'contains',
    'page_path' => '/thank-you',
]);
```

---

## 🧪 Testing Checklist

- [ ] Run database migration: `php artisan migrate`
- [ ] Test GTMDetectionService with sample HTML
- [ ] Test all GTMContainerService methods
- [ ] Test all ConversionTagGenerator methods
- [ ] Test all TriggerGenerator methods
- [ ] Test GTMSetupController endpoints
- [ ] Create unit tests for all services
- [ ] Create integration tests for full flow
- [ ] Test with real customer website
- [ ] Test Path A and Path B flows
- [ ] Test error handling and edge cases

---

## 📊 Status Summary

| Component | Status | Notes |
|-----------|--------|-------|
| GTM Detection | ✅ Complete | Ready for testing |
| Database Schema | ✅ Complete | Migration created |
| Models Updated | ✅ Complete | Fields added to Customer |
| GTMContainerService | ✅ Complete | API integration TODO |
| ConversionTagGenerator | ✅ Complete | All tag types supported |
| TriggerGenerator | ✅ Complete | All trigger types supported |
| Setup Controller | ✅ Complete | Endpoints ready |
| Routes | ❌ TODO | Add to routes/api.php |
| Frontend Components | ❌ TODO | Create Vue/React components |
| Google OAuth | ❌ TODO | Implement token management |
| Unit Tests | ❌ TODO | Create comprehensive tests |
| Beta Testing | ❌ TODO | Test with real customers |
| Google API Integration | ⚠️ TODO | Implement actual API calls |

---

## 🎯 This Week's Goals

1. ✅ Build all core services
2. ✅ Create database migration
3. ✅ Create controller with endpoints
4. → Run migration and test detection
5. → Create routes for GTM endpoints
6. → Test with real customer website
7. → Create frontend form components
8. → Beta test with 1-2 customers

---

## 📞 Support & Questions

For implementation questions:
- Review the GTM documentation in `/docs/GTM/`
- Check the service comments for usage examples
- Refer to the TODO comments for remaining API integration work

For GTM API documentation:
- [Google Tag Manager API Docs](https://developers.google.com/tag-manager/api/v2)
- [GTM Container API Reference](https://developers.google.com/tag-manager/api/v2/reference)
