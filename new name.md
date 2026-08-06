# Kim-Fay Sight

**Product name:** Kim-Fay Sight  
**Short name:** Sight  
**Tagline:** See the business clearly.  
**Meaning:** Sight means **sees every business procedure** — orders, inventory, fulfilment, credit, risk, and team workflows in one control tower.

**Domain:** https://sight.fayshop.co.ke  
**Legacy domain:** https://orderwatch.fayshop.co.ke → permanent redirect to Sight

---

## Brand lines

| Use | Copy |
|-----|------|
| Full product name | Kim-Fay Sight |
| Short | Sight |
| Tagline | See the business clearly. |
| Positioning | Sight means sees every business procedure. |
| AI Assistant | Kim-Fay Genius |
| AI prompt cue | “Ask Genius” for reports, risks, explanations and recommendations. |

---

## Visual mark

- Animated eyes SVG on the login page (`src/components/sight-eyes.tsx`)
- Eyes blink and gently look around — reinforces *sight* / *sees everything*
- Used on the auth brand panel (desktop) and compact mark (mobile)

---

## Deploy notes

| Env | Frontend | Worker | Command |
|-----|----------|--------|---------|
| Production | `https://sight.fayshop.co.ke` | `orderwatchkimfay` | `npm run deploy:production` |
| Staging | `https://staging.sight.fayshop.co.ke` | `sight-staging` | `npm run deploy:staging` |
| Legacy | `orderwatch.fayshop.co.ke` → 301 Sight | same as prod | — |

Full guide: [`docs/STAGING-AND-PRODUCTION.md`](docs/STAGING-AND-PRODUCTION.md)

1. Attach custom domain `sight.fayshop.co.ke` to Worker `orderwatchkimfay`.
2. Keep `orderwatch.fayshop.co.ke` attached so the Worker can 301-redirect to Sight (`src/server.ts`).
3. Backend `FRONTEND_URL=https://sight.fayshop.co.ke` and CORS allowlist include Sight + staging.
4. After deploy, verify:
   - `https://sight.fayshop.co.ke/auth` loads
   - `https://orderwatch.fayshop.co.ke/auth` redirects to Sight with path preserved
   - Staging (when ready): `https://staging.sight.fayshop.co.ke/auth`
