# Add Sanitized Acumatica Payload Reference

## Summary
Add a new safe payload reference file under `acumatica-payload` derived from the existing Postman collection. The file will document the Acumatica OAuth token request and SalesOrder fetch request using environment-variable placeholders, not real credentials.

## Key Changes
- Create `acumatica-payload/acumatica-sales-order-payload.reference.json`.
- Include:
  - `token_request`: method, URL, content type, timeout, and form fields.
  - `sales_order_fetch`: method, URL template, required `$expand`, optional `$filter` examples for `OrderNbr` and `CustomerID`.
  - `environment_variables`: the required `ACUMATICA_*` keys expected by Laravel.
- Use placeholders only:
  - `${ACUMATICA_CLIENT_ID}`
  - `${ACUMATICA_CLIENT_SECRET}`
  - `${ACUMATICA_USERNAME}`
  - `${ACUMATICA_PASSWORD}`
  - `${ACUMATICA_BASE_URL}`
  - `${ACUMATICA_ENDPOINT}`
  - `${ACUMATICA_VERSION}`
- Do not modify or duplicate real secrets from the existing Postman collection.

## Test Plan
- Verify the new JSON file parses correctly.
- Verify no raw Acumatica password or client secret appears in the new file.
- Keep the existing Postman collection untouched unless a later cleanup task explicitly sanitizes it.

## Assumptions
- “Payload file” means a project reference artifact for developers/backend integration, not an executable Postman collection.
- Real credentials should remain in `.env` or a secret manager, not in committed payload files.
