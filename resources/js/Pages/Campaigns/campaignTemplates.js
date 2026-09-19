// Campaign Templates for quick start
export const CAMPAIGN_TEMPLATES = [
    {
        id: 'property-listing',
        name: 'Property Listing',
        icon: '🏠',
        description: 'Drive buyer enquiries for a property',
        verticals: ['real_estate'],
        prefill: {
            reason: 'Promoting a residential property listing to generate qualified buyer enquiries and inspection bookings.',
            goals: 'Drive enquiry form submissions, phone calls, and inspection bookings from serious buyers in the local area.',
            primary_kpi: 'Enquiries for under $80 each, at least 5 a week',
            target_market: 'Home buyers actively searching for properties in the local suburb and surrounding areas, aged 25-60, with household income suitable for the property price range.',
            exclusions: 'Renters, property investors seeking commercial property, real estate students, competitors.',
        }
    },
    {
        id: 'seller-leads',
        name: 'Seller Lead Generation',
        icon: '🏡',
        description: 'Win new property listings from sellers',
        verticals: ['real_estate'],
        prefill: {
            reason: 'Generating appraisal requests and listing opportunities from homeowners considering selling.',
            goals: 'Drive free appraisal requests, grow listing pipeline, build brand recognition among homeowners in target suburbs.',
            primary_kpi: 'Appraisal requests for under $120 each',
            target_market: 'Homeowners in target suburbs aged 35-65 who may be considering selling in the next 6-12 months.',
            exclusions: 'Renters, first home buyers, commercial property owners.',
        }
    },
    {
        id: 'product-launch',
        name: 'Product Launch',
        icon: '🚀',
        description: 'Launch a new product or service',
        prefill: {
            reason: 'Launching a new product/service to the market and need to generate awareness and initial sales.',
            goals: 'Generate awareness, drive traffic to product page, achieve initial sales targets.',
            primary_kpi: 'At least $4 of sales for every $1 spent',
        }
    },
    {
        id: 'seasonal-sale',
        name: 'Seasonal Sale',
        icon: '🎁',
        description: 'Promote a limited-time offer',
        prefill: {
            reason: 'Running a seasonal promotion to boost sales and clear inventory.',
            goals: 'Maximize conversions during the promotional period, increase average order value.',
            primary_kpi: 'At least $5 of sales for every $1 spent',
        }
    },
    {
        id: 'brand-awareness',
        name: 'Brand Awareness',
        icon: '📢',
        description: 'Increase brand recognition',
        prefill: {
            reason: 'Building brand awareness and recognition in our target market.',
            goals: 'Reach new audiences, increase brand recall, grow social following.',
            primary_kpi: 'Reach 100,000 people for under $10 per thousand views',
        }
    },
    {
        id: 'lead-generation',
        name: 'Lead Generation',
        icon: '📧',
        description: 'Capture qualified leads',
        prefill: {
            reason: 'Generating qualified leads for our sales team.',
            goals: 'Capture contact information, qualify leads, nurture toward conversion.',
            primary_kpi: 'Leads for under $15 each',
        }
    },
    {
        id: 'blank',
        name: 'Start Fresh',
        icon: '✨',
        description: 'Create from scratch',
        prefill: {}
    }
];
