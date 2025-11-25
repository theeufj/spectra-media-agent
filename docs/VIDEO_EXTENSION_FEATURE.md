# Video Extension Feature

## Overview
The video extension feature allows you to extend Veo-generated videos by up to 7 seconds per extension, with a maximum of 20 extensions (total 148 seconds).

## Components Created

### 1. **GeminiService - `extendVideo()` Method**
Location: `/app/Services/GeminiService.php`

Handles the API call to Veo 3.1 to extend videos:
- Accepts video URI from previous generation
- Sends extension prompt
- Returns operation name for polling

**Requirements:**
- Input video must be Veo-generated (from previous operation)
- Video must be ≤141 seconds
- Aspect ratio: 9:16 or 16:9
- Resolution: 720p

### 2. **ExtendVideo Job**
Location: `/app/Jobs/ExtendVideo.php`

Queue job that:
- Validates source video can be extended
- Checks extension count limit (max 20)
- Dispatches extension request to Gemini
- Creates new VideoCollateral record for extended video
- Links to parent video via `parent_video_id`

### 3. **Database Migration**
Location: `/database/migrations/2025_11_25_020336_add_video_extension_fields_to_video_collaterals_table.php`

Added columns to `video_collaterals` table:
- `gemini_video_uri` - Stores Gemini video URI for extensions
- `parent_video_id` - Links to source video if this is an extension
- `extension_count` - Tracks number of extensions (0-20)

### 4. **Controller Method**
Location: `/app/Http/Controllers/VideoCollateralController.php`

`extend()` method:
- Validates user authorization
- Checks video can be extended
- Validates extension prompt
- Dispatches ExtendVideo job
- Returns JSON response with success/error

### 5. **API Route**
Location: `/routes/web.php`

```php
POST /video-collaterals/{video}/extend
```

### 6. **Model Updates**
Location: `/app/Models/VideoCollateral.php`

Added:
- `gemini_video_uri`, `parent_video_id`, `extension_count` to fillable
- `parentVideo()` relationship
- `extensions()` relationship  
- `canBeExtended()` helper method

## Usage

### API Request
```bash
POST /video-collaterals/{video_id}/extend
Content-Type: application/json

{
  "prompt": "Track the butterfly into the garden as it lands on an orange origami flower."
}
```

### Response
```json
{
  "success": true,
  "message": "Video extension has been queued. This process can take several minutes.",
  "extensions_remaining": 19
}
```

### Error Cases
- Video not completed: 400
- Not a Veo-generated video: 400
- Extension limit reached (20): 400
- Unauthorized: 403
- Server error: 500

## Workflow

1. **User requests extension** → POST to `/video-collaterals/{id}/extend`
2. **Controller validates** → Checks authorization, video status, extension count
3. **ExtendVideo job dispatched** → Queued for processing
4. **GeminiService.extendVideo()** → Sends request to Veo 3.1 API
5. **New VideoCollateral created** → Status: 'generating', linked to parent
6. **CheckVideoStatus job** → Polls until complete (reused from regular generation)
7. **Video ready** → Downloads, uploads to S3, updates record with CloudFront URL

## Related Files

### Video Stitching (Additional Feature)
If you want to stitch multiple video segments together for longer content, use:
- `/app/Jobs/StitchVideoSegments.php` - FFmpeg-based concatenation
- `/app/Services/VideoGeneration/VideoSegmentationService.php` - Script splitting logic

These allow generating multiple video segments and stitching them into a single longer video.

## Technical Notes

- **Veo 3.1 Model**: `veo-3.1-generate-preview`
- **Extension Duration**: 7 seconds per extension
- **Max Total Length**: 148 seconds (initial + 20 × 7s extensions)
- **Output**: Single combined video (original + extension)
- **FFmpeg**: Installed and available at `/opt/homebrew/bin/ffmpeg`

## Example Use Case

```php
// Generate initial 8-second video
$video = VideoCollateral::where('status', 'completed')->first();

// Extend it by 7 seconds
ExtendVideo::dispatch($video, "Continue the scene as the camera pans right");

// Result: Single 15-second video combining both parts
```
