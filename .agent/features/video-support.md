# Video Support in Shared Albums

Support Google Photos albums that contain both photos and videos, while keeping
the current viewer architecture (Swiper + fullscreen) and avoiding a separate
lightbox stack.

---

## Why

Some shared albums include videos. Today, the plugin treats album entries as
image-only, so video items cannot be played inside the plugin viewer.

Goal: make mixed-media albums work naturally in all modes.

---

## Feasibility Check (Validated)

Using this public test album:

`https://photos.app.goo.gl/tp6pUqQ4KecnqxT49`

we verified:

1. The Google Photos `ds:1` payload includes the video item.
2. Direct `video-downloads.googleusercontent.com/...` links from payload can
   return HTTP `500` (often short-lived/signed).
3. The media base URL (`lh3.googleusercontent.com/pw/...`) can be transformed:
   - `base=dv` -> `302` redirect to fresh downloadable/streamable MP4
   - following redirect returns `200` with `content-type: video/mp4`
4. `=dv` on a photo base URL returned `404` in our check.

Conclusion: video playback is possible without adding PhotoSwipe/Glightbox, by
deriving playable URLs from media base URLs.

---

## Proposed Product Behavior

### Single / Player mode

- Photo slides keep current behavior.
- Video slides render a native HTML5 `<video controls playsinline>`.
- On video slide:
  - slider autoplay pauses,
  - when video ends, slider can advance if autoplay is enabled.

### Carousel / Carousel-to-single

- In carousel preview, videos show poster/thumbnail + play badge.
- Opening into fullscreen/player mode plays video in-place (same viewer).

### Grid mode

- Video tiles render like photos, with a play badge overlay.
- Clicking tile opens fullscreen player at that media item.
- Fullscreen can play video directly in the same shared player flow.

---

## Technical Approach

### 1) Data model upgrade

Move from image-only entries to media entries:

- `type`: `image | video`
- `base`: Google media base URL (lh3...)
- `preview`: thumbnail URL
- `full`: full image URL (for images)
- `video`: derived playable URL (for videos; from `base + "=dv"` resolution)
- optional metadata: `width`, `height`, `duration`

### 2) Detection strategy

Preferred:

- Detect video from Google payload metadata when available.

Fallback:

- Probe `base + "=dv"` (lazy, per item when needed):
  - `302/200 video/*` => video
  - `404/4xx` => non-video

### 3) URL resolution strategy

- Do not rely on stale `video-downloads...` URLs embedded in HTML.
- Resolve fresh URL from `base=dv` when playback is requested.
- Cache derived result in memory per gallery session.

### 4) Renderer + JS updates

- Keep existing Swiper viewer; no new lightbox dependency.
- Update slide builder to render `<img>` or `<video>` per media type.
- Update progressive loader/autoplay handlers for video-aware behavior.
- Add grid play badge and video tile handling.

### 5) Optional server proxy (recommended)

Direct client playback may fail depending on Google signature/session policy.
Add optional proxy endpoint for video retrieval (same hardening style as current
download endpoint):

- host allowlist (`googleusercontent.com`),
- SSRF protections,
- range request support,
- passthrough of content type/length/range headers.

This dramatically improves reliability across browsers and sessions.

---

## New/Updated Parameters (Proposal)

| Parameter | Default | Purpose |
|---|---|---|
| `show-videos` | `true` | Include videos from mixed albums. |
| `video-autoplay` | `false` | Auto-start video when its slide becomes active. |
| `video-muted` | `false` | Start videos muted (if autoplay policy requires). |
| `video-loop` | `false` | Loop current video instead of advancing slider. |

If we want a minimal v1: ship without new params and use sensible defaults.

---

## Acceptance Criteria

1. Mixed album (2 photos + 1 video) shows all 3 items in plugin.
2. Video plays in fullscreen/player mode with native controls.
3. Slider autoplay pauses during video playback and resumes correctly.
4. Grid mode opens video item correctly from its tile.
5. No regressions for image-only albums.
6. iPhone `playsinline` behavior is validated.

---

## Risks / Notes

- Google may change undocumented payload shape or media URL behavior.
- `=dv` behavior is practical but unofficial; keep fallback logic and graceful
  degradation.
- Video bandwidth and CPU cost are higher than images; preload policy must stay
  conservative.

---

## Recommendation

Implement video support inside the existing Swiper/fullscreen architecture.
Do not introduce a parallel lightbox framework. This keeps UX consistent and
limits long-term maintenance overhead.
