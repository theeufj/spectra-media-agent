# Video Extension - User Guide

## How to Use Video Extension

### Step 1: Generate a Video
1. Navigate to your campaign's Collateral page
2. Click "Generate Video" for any strategy
3. Wait for the video to complete processing (status: completed)

### Step 2: Extend the Video
1. **Hover over a completed video** - An "Extend" button appears in the bottom-right corner
2. **Click "Extend"** - Opens the video extension modal
3. **Write your extension prompt** - Describe how you want to continue the video
   - Example: "Continue the scene with a smooth pan to the right"
   - Example: "Zoom in slowly on the main subject"
4. **Click "Extend Video (+7s)"** - Submits the extension request

### Step 3: Wait for Processing
- Extension takes several minutes (similar to initial generation)
- The page will auto-refresh to show the new extended video
- The extended video replaces the original with a combined version

## Visual Indicators

### Extension Badge
- **Blue badge** showing "Extended Nx" appears on videos that have been extended
- Shows how many times the video has been extended (e.g., "Extended 2x")

### Extend Button
- Only visible on **hover** for completed Veo-generated videos
- Automatically hidden if:
  - Video is still processing
  - Video is not Veo-generated (no `gemini_video_uri`)
  - Maximum extensions reached (20)

### Extensions Remaining
- Shown in the extension modal
- Format: "Extensions remaining: X/20"
- Each extension adds 7 seconds to the video

## UI Features

### Extension Modal Includes:
1. **Video Preview** - View the current video before extending
2. **Extension Info** - Details about the extension feature
3. **Prompt Input** - Text area for your extension instructions
4. **Example Prompts** - Click-to-use example prompts for inspiration
5. **Warning Messages** - Alerts if video can't be extended
6. **Progress Indicator** - Shows "Starting Extension..." while processing

### Example Prompts (Click to Use):
- "Continue the scene with a smooth pan to the right, revealing more of the environment"
- "Zoom in slowly on the main subject while maintaining focus"
- "Transition to a different angle showing the same scene from above"
- "Add a gentle fade as the scene continues naturally"

## Restrictions

### Videos that CAN be extended:
✅ Status is "completed"
✅ Generated with Veo (has `gemini_video_uri`)
✅ Less than 20 extensions already applied

### Videos that CANNOT be extended:
❌ Still processing (status: generating, pending, etc.)
❌ Not Veo-generated (uploaded or from other sources)
❌ Already reached 20 extensions (148 seconds total)
❌ Failed generation

## Technical Details

- **Extension Duration**: 7 seconds per extension
- **Maximum Extensions**: 20 times
- **Maximum Total Length**: 148 seconds (8s initial + 20×7s extensions)
- **Output**: Single combined video (original + extension)
- **Processing Time**: 1-3 minutes per extension
- **Model**: Veo 3.1

## Backend API

### Endpoint
```
POST /video-collaterals/{video_id}/extend
```

### Request Body
```json
{
  "prompt": "Your extension prompt here"
}
```

### Success Response
```json
{
  "success": true,
  "message": "Video extension has been queued...",
  "extensions_remaining": 19
}
```

### Error Responses
- **400**: Video cannot be extended (not completed, not Veo-generated, limit reached)
- **403**: Unauthorized access
- **422**: Validation failed (missing or invalid prompt)
- **500**: Server error during extension

## Workflow

1. **User hovers** over completed video → Extend button appears
2. **User clicks Extend** → Modal opens with current video preview
3. **User enters prompt** → Describes desired continuation
4. **User clicks "Extend Video"** → POST request sent to backend
5. **Backend validates** → Checks permissions, video status, extension count
6. **ExtendVideo job dispatched** → Queued for async processing
7. **Gemini API called** → Veo 3.1 generates 7-second extension
8. **CheckVideoStatus polls** → Waits for completion
9. **Video downloaded & uploaded** → Combined video stored in S3
10. **Frontend updates** → New video appears with extension badge

## Tips for Best Results

1. **Be specific** about camera movements or scene changes
2. **Maintain continuity** with the original video's theme
3. **Keep prompts concise** (under 200 characters works best)
4. **Preview first** - Watch your video before extending
5. **Use examples** - Click example prompts for guidance
6. **Chain extensions** - Extend multiple times for longer content

## Integration Points

### Files Modified:
- `ExtendVideoModal.jsx` - New modal component
- `Collateral.jsx` - Added extend button and modal integration
- `VideoCollateralController.php` - Added extend() method
- `GeminiService.php` - Added extendVideo() method
- `ExtendVideo.php` - New job for processing extensions
- `CheckVideoStatus.php` - Updated to store gemini_video_uri
- `VideoCollateral.php` - Added extension fields and relationships

### Database Fields:
- `gemini_video_uri` - Stores Gemini file reference for extensions
- `parent_video_id` - Links extended videos to their source
- `extension_count` - Tracks number of extensions (0-20)

### Route:
```php
POST /video-collaterals/{video}/extend
```
