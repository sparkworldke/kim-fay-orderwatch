# Customer Contacts (CRM) & FOL Requestor

CRM-style **contacts attached to an Account/Customer**, with reuse on **New FOL** as the site requestor.

**Last updated:** 2026-07-16  
**Module area:** KP CRM From Workflow · Customers  
**Status:** Implemented

## 1. What this is

| Concept | Meaning |
|---------|---------|
| **Account / Customer** | Acumatica customer (`acumatica_customers`) |
| **Contact** | A person at that account (name, phone, email, designation) |
| **FOL requestor** | Customer-side site contact for a FOL request — **not** the sales consultant |

A customer can have **several contacts**. Contacts are stored in OrderWatch (not synced from Acumatica in v1).

## 2. Designations (predefined)

| Key | Label |
|-----|--------|
| `ceo_md` | CEO/MD |
| `cfo_finance` | CFO/Head of Finance |
| `cco_coo` | CCO/COO |
| `head_procurement` | Head of Procurement |
| `custom` | Custom (user types job title) |

Custom designation **requires** a free-text label (e.g. “Site Manager”).

## 3. Who fills what on New FOL

| Field | Whose details |
|--------|----------------|
| **KP account** | Customer company in Acumatica |
| **Requestor (name, phone, email)** | **Site / customer contact** who requested the FOL |
| **Sales consultant** | Logged-in OrderWatch user (automatic) |

## 4. How to use

### 4.1 Manage contacts on an account

1. Open **Customers** → a customer documents page (`/app/customer-orders/{customerId}`)  
2. Use the **Contacts** card  
3. **Add contact** — designation, first/last name, phone, email, optional **Primary**  
4. **Edit** updates designation / details / primary  
5. **Remove** deactivates the contact (soft delete)  
6. Only one primary contact is kept per account

### 4.2 Use contacts on New FOL

1. **KP CRM From Workflow** → KP FOL → **New FOL**  
2. Select **KP account**  
3. **Requestor contact** dropdown: existing as `Designation — Full name`, or **Enter new requestor…**  
4. New requestor: designation + name/phone/email + ☑ **Save this requestor as a contact on the account**  
5. Saved contacts reappear next time for that account  

## 5. Data model

### `customer_contacts`
- `customer_acumatica_id`, `designation_key`, `designation_label`, `first_name`, `last_name`, `phone`, `email`, `is_primary`, `is_active`, `created_by_user_id`

### `fol_requests`
- `requestor_*` snapshot fields  
- `requestor_contact_id` optional FK to `customer_contacts`

## 6. API

| Method | Path |
|--------|------|
| GET | `/api/customer-contact-designations` |
| GET | `/api/customers/{customerId}/contacts` |
| POST | `/api/customers/{customerId}/contacts` |
| PUT | `/api/contacts/{id}` |
| DELETE | `/api/contacts/{id}` |

FOL create may send: `requestor_contact_id`, `save_requestor_as_contact`, `requestor_designation_key`, `requestor_designation_label`.

## 7. Key files

- `backend/app/Models/CustomerContact.php`
- `backend/app/Services/Crm/CustomerContactService.php`
- `backend/app/Http/Controllers/Api/CustomerContactController.php`
- `src/components/customer-contacts-card.tsx`
- `src/hooks/useCustomerContacts.ts`
- `src/routes/app.kp.fol.new.tsx`
- `src/routes/app.customer-orders.$customerId.tsx`
- Migrations `2026_07_21_000001_*` and `2026_07_21_000002_*`

## 8. Deploy

```bash
php artisan migrate --force
npm run build && npx wrangler deploy
```

## 9. Tests

```bash
php vendor/bin/phpunit tests/Feature/CustomerContactTest.php
```

## Related

- `docs/notifications-and-backorders.md`
- `kp/fol-requests.md`

## FOL Products (related admin)

**FOL Products** is not a separate top-level Admin tab. It lives under:

**KP CRM → FOL Settings** → **FOL Products (eligible SKUs)** panel

- Search / mark inventory as FOL-eligible
- Quick enable/disable by SKU
- Bulk CSV upload
- API: `GET/PUT admin/fol/products`, `POST admin/fol/products/bulk-upload`
- Files: `src/routes/app.administration.tsx` (`FolProductsPanel`), `backend/app/Http/Controllers/Api/Admin/FolProductsController.php`
