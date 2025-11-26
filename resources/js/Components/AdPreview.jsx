import React from 'react';

/**
 * AdPreview - Shows how ads will appear on different platforms
 */

// Google Search Ad Preview
export function GoogleSearchPreview({ headlines = [], descriptions = [], url = 'example.com' }) {
    const displayUrl = url.replace(/^https?:\/\//, '').split('/')[0];
    
    return (
        <div className="bg-white border border-gray-200 rounded-lg p-4 max-w-[400px] font-sans">
            <div className="text-xs text-gray-500 mb-1">Ad · {displayUrl}</div>
            <h3 className="text-lg text-blue-800 hover:underline cursor-pointer leading-tight">
                {headlines.slice(0, 3).join(' | ') || 'Your Ad Headline Here'}
            </h3>
            <p className="text-sm text-gray-700 mt-1 line-clamp-2">
                {descriptions[0] || 'Your ad description will appear here. Make it compelling!'}
            </p>
        </div>
    );
}

// Google Display Ad Preview
export function GoogleDisplayPreview({ headline, description, imageUrl, logoUrl }) {
    return (
        <div className="bg-white border border-gray-200 rounded-lg overflow-hidden max-w-[300px]">
            {imageUrl ? (
                <img 
                    src={imageUrl} 
                    alt="Ad preview" 
                    className="w-full h-40 object-cover"
                />
            ) : (
                <div className="w-full h-40 bg-gradient-to-br from-gray-200 to-gray-300 flex items-center justify-center text-gray-500">
                    <span className="text-4xl">🖼️</span>
                </div>
            )}
            <div className="p-3">
                <div className="flex items-center space-x-2 mb-2">
                    {logoUrl ? (
                        <img src={logoUrl} alt="Logo" className="w-6 h-6 rounded" />
                    ) : (
                        <div className="w-6 h-6 bg-gray-300 rounded" />
                    )}
                    <span className="text-xs text-gray-500">Ad</span>
                </div>
                <h4 className="font-semibold text-sm text-gray-900 line-clamp-2">
                    {headline || 'Ad Headline'}
                </h4>
                <p className="text-xs text-gray-600 mt-1 line-clamp-2">
                    {description || 'Ad description text'}
                </p>
                <button className="mt-2 px-3 py-1 bg-blue-600 text-white text-xs rounded hover:bg-blue-700">
                    Learn More
                </button>
            </div>
        </div>
    );
}

// Facebook Feed Ad Preview
export function FacebookFeedPreview({ 
    pageName = 'Your Page', 
    headline, 
    primaryText, 
    description,
    imageUrl, 
    ctaText = 'Learn More' 
}) {
    return (
        <div className="bg-white border border-gray-200 rounded-lg max-w-[400px] font-sans">
            {/* Header */}
            <div className="p-3 flex items-center space-x-2">
                <div className="w-10 h-10 bg-gradient-to-br from-blue-500 to-blue-600 rounded-full flex items-center justify-center text-white font-bold">
                    {pageName.charAt(0)}
                </div>
                <div>
                    <p className="font-semibold text-sm">{pageName}</p>
                    <p className="text-xs text-gray-500">Sponsored · 🌐</p>
                </div>
            </div>
            
            {/* Primary Text */}
            <div className="px-3 pb-2">
                <p className="text-sm text-gray-800 line-clamp-3">
                    {primaryText || 'Your ad primary text will appear here. This is the main message that captures attention.'}
                </p>
            </div>
            
            {/* Image */}
            {imageUrl ? (
                <img 
                    src={imageUrl} 
                    alt="Ad preview" 
                    className="w-full h-52 object-cover"
                />
            ) : (
                <div className="w-full h-52 bg-gradient-to-br from-gray-200 to-gray-300 flex items-center justify-center">
                    <span className="text-6xl">🖼️</span>
                </div>
            )}
            
            {/* Link Preview */}
            <div className="bg-gray-100 p-3">
                <p className="text-xs text-gray-500 uppercase">yourwebsite.com</p>
                <h4 className="font-semibold text-sm text-gray-900 mt-1">
                    {headline || 'Your Ad Headline'}
                </h4>
                <p className="text-xs text-gray-600 mt-0.5 line-clamp-1">
                    {description || 'Link description'}
                </p>
            </div>
            
            {/* CTA Button */}
            <div className="p-3 border-t">
                <button className="w-full py-2 bg-gray-200 text-gray-800 font-semibold text-sm rounded hover:bg-gray-300">
                    {ctaText}
                </button>
            </div>
            
            {/* Engagement */}
            <div className="px-3 pb-3 flex items-center justify-between text-gray-500 text-xs">
                <div className="flex items-center space-x-1">
                    <span>👍</span>
                    <span>❤️</span>
                    <span>Preview</span>
                </div>
                <span>Comment · Share</span>
            </div>
        </div>
    );
}

// Instagram Feed Ad Preview
export function InstagramFeedPreview({ 
    username = 'yourbrand', 
    headline,
    imageUrl, 
    caption,
    ctaText = 'Learn More' 
}) {
    return (
        <div className="bg-white border border-gray-200 rounded-lg max-w-[350px] font-sans">
            {/* Header */}
            <div className="p-3 flex items-center justify-between">
                <div className="flex items-center space-x-2">
                    <div className="w-8 h-8 bg-gradient-to-br from-purple-500 to-pink-500 rounded-full" />
                    <div>
                        <p className="font-semibold text-sm">{username}</p>
                        <p className="text-xs text-gray-500">Sponsored</p>
                    </div>
                </div>
                <span className="text-gray-400">•••</span>
            </div>
            
            {/* Image */}
            {imageUrl ? (
                <img 
                    src={imageUrl} 
                    alt="Ad preview" 
                    className="w-full aspect-square object-cover"
                />
            ) : (
                <div className="w-full aspect-square bg-gradient-to-br from-purple-100 to-pink-100 flex items-center justify-center">
                    <span className="text-6xl">📸</span>
                </div>
            )}
            
            {/* CTA */}
            <div className="px-3 py-2 border-b flex items-center justify-between">
                <span className="text-sm font-semibold">{headline || 'Ad Headline'}</span>
                <button className="px-3 py-1 bg-blue-500 text-white text-xs rounded-full">
                    {ctaText}
                </button>
            </div>
            
            {/* Actions */}
            <div className="px-3 py-2 flex items-center space-x-4">
                <span className="text-2xl">♡</span>
                <span className="text-2xl">💬</span>
                <span className="text-2xl">➤</span>
            </div>
            
            {/* Caption */}
            <div className="px-3 pb-3">
                <p className="text-sm">
                    <span className="font-semibold">{username}</span>{' '}
                    <span className="text-gray-800">
                        {caption || 'Your caption will appear here with relevant hashtags and call to action...'}
                    </span>
                </p>
            </div>
        </div>
    );
}

// Combined Preview Component
export default function AdPreviewPanel({ 
    adCopy, 
    images = [], 
    platform,
    brandName = 'Your Brand',
    websiteUrl = 'yourwebsite.com'
}) {
    const headlines = adCopy?.headlines || [];
    const descriptions = adCopy?.descriptions || [];
    const primaryImage = images[0]?.cloudfront_url || null;
    
    return (
        <div className="bg-gray-100 rounded-lg p-6">
            <h3 className="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                <span className="mr-2">👁️</span>
                Ad Preview
            </h3>
            <p className="text-sm text-gray-500 mb-6">
                See how your ads will appear across different placements
            </p>
            
            <div className="flex gap-6 overflow-x-auto pb-4">
                {/* Google Search */}
                <div className="flex-shrink-0">
                    <p className="text-xs text-gray-500 mb-2 font-medium">Google Search</p>
                    <GoogleSearchPreview 
                        headlines={headlines}
                        descriptions={descriptions}
                        url={websiteUrl}
                    />
                </div>
                
                {/* Google Display */}
                <div className="flex-shrink-0">
                    <p className="text-xs text-gray-500 mb-2 font-medium">Google Display</p>
                    <GoogleDisplayPreview 
                        headline={headlines[0]}
                        description={descriptions[0]}
                        imageUrl={primaryImage}
                    />
                </div>
                
                {/* Facebook Feed */}
                <div className="flex-shrink-0">
                    <p className="text-xs text-gray-500 mb-2 font-medium">Facebook Feed</p>
                    <FacebookFeedPreview 
                        pageName={brandName}
                        headline={headlines[0]}
                        primaryText={descriptions[0]}
                        description={headlines[1]}
                        imageUrl={primaryImage}
                    />
                </div>
                
                {/* Instagram */}
                <div className="flex-shrink-0">
                    <p className="text-xs text-gray-500 mb-2 font-medium">Instagram</p>
                    <InstagramFeedPreview 
                        username={brandName.toLowerCase().replace(/\s+/g, '')}
                        headline={headlines[0]}
                        imageUrl={primaryImage}
                        caption={descriptions[0]}
                    />
                </div>
            </div>
            
            <p className="text-xs text-gray-400 mt-4 text-center">
                Previews are approximations. Actual appearance may vary slightly.
            </p>
        </div>
    );
}
