import { useId, useState } from 'react';

export default function GoogleDisplayBanners({ image, canDownload }) {
    const panelId = useId();
    const [expanded, setExpanded] = useState(false);
    const [selectedSize, setSelectedSize] = useState(null);
    const banner = image.display_banner_preview;
    if (!banner) return null;

    const sizes = Object.keys(banner.sizes);
    const size = sizes.includes(selectedSize) ? selectedSize : sizes[0];
    if (!size) return null;
    const label = size.replace('x', ' × ');
    const params = { image_collateral: image.id, size, hash: banner.sizes[size] };

    return (
        <div className="mt-2 rounded-lg border border-gray-200 bg-white">
            <button type="button" aria-expanded={expanded} aria-controls={panelId}
                className="block w-full px-3 py-2 text-left text-sm font-semibold text-brand-dark hover:underline"
                onClick={() => setExpanded(!expanded)}>
                Review Google Display banners ({sizes.length})
            </button>
            {expanded && <div id={panelId} className="space-y-3 border-t border-gray-200 p-3">
                <p className="text-xs text-gray-600">Fixed-size artwork for uploaded display ads. Google determines eligibility and placement.</p>
                <label className="block text-sm font-medium text-gray-700">
                    Banner size
                    <select value={size} onChange={e => setSelectedSize(e.target.value)} className="ml-2 rounded-md border-gray-300 py-1 text-sm">
                        {sizes.map(value => <option key={value} value={value}>{value.replace('x', ' × ')} px</option>)}
                    </select>
                </label>
                {banner.regions?.[size] && <p className="text-xs text-gray-600">
                    Regional format for {new Intl.DisplayNames(['en'], { type: 'region' }).of(banner.regions[size])}.
                </p>}
                <figure>
                    <img src={route('image-collaterals.display-banner', params)} alt={`${label} display banner`}
                        className="mx-auto max-h-96 max-w-full object-contain" />
                    <figcaption className="mt-2 break-words text-sm text-gray-700">
                        {banner.brand && <span className="block">{banner.brand}</span>}
                        {banner.headline && <span className="block font-semibold">{banner.headline}</span>}
                        {banner.cta && <span className="block">Call to action · {banner.cta}</span>}
                    </figcaption>
                </figure>
                {canDownload && <a className="inline-block text-sm font-medium text-brand-dark underline"
                    download href={route('image-collaterals.display-banner', { ...params, download: 1 })}>
                    Download {label} JPG
                </a>}
            </div>}
        </div>
    );
}
