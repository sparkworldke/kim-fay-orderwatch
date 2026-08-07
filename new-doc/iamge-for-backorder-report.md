Using OpenAI Api for image and context can we try create an image to show this via the number on the backorde page add generate an image report button see the summaries. make it an executive report.

# AI Backorder Executive Image Report

## Summary

Add a “Generate Image Report” button to the existing Backorders page. It will use OpenAI for a branded visual background and concise executive commentary, while Sight renders every KPI and chart value deterministically from the existing filtered backorder metrics.

The result opens in a preview dialog and can be downloaded as a landscape PNG. This avoids image-model errors in financial figures while still providing an AI-designed executive report. Use `gpt-image-2`, OpenAI’s recommended current API image model. [Official model documentation](https://developers.openai.com/api/docs/models/gpt-image-2)

## Implementation Changes

- Add `POST /api/operations/backorders/executive-image` under existing authentication and portfolio scoping.
- Accept the same filters as the Backorders summary and Excel export: dates, segment, brand, customer group, warehouse, reason, and search.
- Recompute all figures server-side using the existing canonical backorder query, transformer, and metrics service; never accept financial totals from the browser.
- Build an executive payload containing:
  - Revenue at Risk
  - Ready to release
  - Blocked—no stock
  - Open lines, SKUs, customers, and orders
  - Manufactured versus Trading exposure
  - Top brands, parent customers, SKUs, and reasons
  - Filter period and generation timestamp
- Ask the existing OpenAI text integration for three short executive observations and recommended actions. Require structured JSON and reject invented figures.
- Call `v1/images/generations` with `OPENAI_IMAGE_MODEL`, defaulting to `gpt-image-2`, for a landscape Kim-Fay executive-report background containing no numbers, charts, or generated text.
- Cache the AI result by user, permissions, filters, and metric-data hash for one hour to limit duplicate cost. Do not cache across users with different portfolio scopes.
- If image or narrative generation fails, return the exact metrics with a deterministic branded fallback so report generation remains usable.

## UI and Image Composition

- Place “Generate Image Report” beside the existing Excel download action without restructuring the current Backorders UI.
- Show a progress state covering metric preparation, AI generation, and rendering.
- Compose the final report in the browser on a fixed 1536×1024 canvas:
  - Kim-Fay header, selected period, and generated timestamp
  - Exactly three primary KPI cards
  - Manufactured/Trading split
  - Top brands, customers, and SKUs
  - Three AI-authored executive observations
  - Confidentiality/footer note and active filter summary
- Draw all text, currency, percentages, bars, and labels from the server’s structured payload—not from pixels produced by the image model.
- Open a preview dialog with Download PNG, Regenerate, and Close actions.
- Use a filename such as `backorders-executive-report-20260807-1430.png`.
- Disable generation when no OpenAI key exists only if the deterministic fallback is also unavailable; otherwise explain that a non-AI report will be produced.

## Interfaces and Safeguards

- Response contract: `metrics`, `breakdowns`, `narrative`, `background_image`, `filters`, `generated_at`, `cached`, and `ai_status`.
- Keep the OpenAI key server-side through `AiConnectorService`; never expose prompts, credentials, or raw provider errors to the browser.
- Apply the same role and customer-portfolio scope as the current Backorders summary and export.
- Do not label active exposure as lost sales.
- Round presentation values only after canonical calculations; maintain equality with the current dashboard and Excel summary.
- Add configured timeouts, provider health updates, structured logging, prompt-size limits, and graceful 429/timeout handling.

## Test Plan

- Verify generated-report totals exactly match the Backorders cards for identical filters.
- Verify segment, brand, customer, date, warehouse, and reason filters affect both metrics and report.
- Verify scoped users cannot generate reports containing customers outside their portfolio.
- Verify the final PNG contains the exact server-provided figures and not AI-generated numeric text.
- Verify OpenAI missing-key, timeout, 429, malformed narrative, and image failure paths produce a usable fallback.
- Verify repeated identical requests use the scoped cache while changed filters or metrics generate a new report.
- Verify preview, download, regenerate, mobile loading state, and filename behavior.
- Add backend feature tests with mocked OpenAI responses and frontend tests for canvas composition and preview actions.

## Assumptions

- Default delivery is preview plus PNG download.
- Default method is AI background and narrative with a deterministic exact-data overlay.
- Generation is synchronous with a 180-second client timeout; asynchronous Downloads integration can follow if production latency warrants it.
- Reports are not persisted in V1.
- The existing backorder calculations, filters, and access controls remain authoritative.
