# Reusable setup examples

These files illustrate a fictional business, **Example Home Cleaning**, at the reserved example.com domain. They are shared static assets on the unpaid setup offer, not customer deliverables or promises about asset counts.

- `home-cleaning.webp`: 1200 × 675 landscape image ad, approximately 79 KiB.
- `home-cleaning.mp4`: silent 8-second, 1280 × 720 motion ad, H.264 / yuv420p, 30 fps, approximately 640 KiB. MP4 metadata is at the start for progressive playback.
- `home-cleaning-poster.webp`: approximately 65 KiB preview frame.

## Production

The image was created once with the built-in ImageGen tool, then resized and encoded to WebP with cwebp. The video was rendered from that image with FFmpeg using a gentle zoom out and warm cream fades. It is an illustrative motion ad, not generated live-action footage. The poster was extracted from the rendered video.

The page makes no AI calls, consumes no customer generation allowance, and uses the same files for every visitor. Video playback is user initiated. Changing the offer or customer does not require regenerating these examples.

## Final image prompt

```text
Use case: ads-marketing.
Asset type: evergreen fictional landscape image ad for an onboarding gallery, 16:9 wide composition.
Primary request: create a polished yet approachable home-cleaning service ad for the fictional business "Example Home Cleaning", using the reserved demo URL "example.com".
Scene: photorealistic bright cream living room with a light natural-wood coffee table, tidy cream sofa and warm daylight; a professional cleaner wearing a plain muted teal uniform is carefully wiping the table with a teal cloth. Believable anatomy, natural textures, calm comfortable everyday home.
Design: combine the photo with a restrained warm-cream and deep-teal typographic area, ample breathing room, crisp readable modern sans-serif hierarchy, commercially finished image ad. No exaggerated claims, ratings, badges, discounts or pricing.
Text, verbatim, and no other text:
"Example Home Cleaning"
"Come home to a fresh start."
"Explore cleaning services"
"example.com"
Constraints: clearly a generic fictional sample; no real business logos or identifiable customer material. Preserve room and readable copy within safe margins for a final 1200x675 landscape crop. Opaque background, no watermark, no device frame, no browser UI.
```

## Motion treatment

Animate the complete fictional image ad for eight seconds in a 16:9 frame. Gently widen from 102.5% to the full image while keeping all copy in view. Use brief warm cream fades at the opening and close, no audio, and no additional claims or text. Export a browser-compatible H.264 MP4 with faststart and a WebP poster.
