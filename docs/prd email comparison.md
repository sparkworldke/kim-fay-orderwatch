## PRD Update: PO ↔ Email Matching Logic

### Primary Matching Rule

The PO-to-email matching engine must use the **Acumatica PO number** as the primary identifier for determining whether an email corresponds to a purchase order.

The system should attempt to detect the Acumatica PO number across all relevant parts of the email record, including:

* **Email subject line**
* **Email body content**
* **Attachment file name**
* **Attachment document content** (where parsable text is available)
* **Email thread context**, if the PO number appears in earlier or related messages in the same conversation

---

## Updated Matching Requirement

When evaluating whether an email corresponds to a Purchase Order, the AI must first check whether the **Acumatica PO number** exists in any of the following sources:

1. **PO Number in Email Subject**

   * Example: `PO 004512 attached`
   * Example: `Re: Kim-Fay Order PO004512`

2. **PO Number in Email Body**

   * Example: `Please process PO004512 for delivery next week.`
   * Example: `Attached is our PO 004512.`

3. **PO Number in Attachment Name**

   * Example: `PO004512.pdf`
   * Example: `KimFay_PO_004512_Rev2.xlsx`

4. **PO Number in Attachment Content**

   * Example: The attached PO document itself contains `PO004512`
   * Example: Supporting document or order amendment references the same PO number

5. **PO Number in the Email Thread**

   * If the current email does not explicitly mention the PO number, but the same email thread contains the PO number in an earlier message, the system may use that as supporting evidence for the match.

---

## Matching Logic Priority

The matching engine should evaluate correspondence in the following order of importance:

### Tier 1 – Primary Identifier Match

* Exact match between the **Acumatica PO number** and a PO number found in:

  * email subject
  * email body
  * attachment filename
  * attachment content
  * email thread history

### Tier 2 – Supporting Validation

If a PO number match is found, the system should then validate the match using additional fields where available:

* supplier / customer name
* SKU / item descriptions
* ordered quantities
* unit prices
* total order value
* requested delivery date
* branch / delivery location

### Tier 3 – Discrepancy Review

If the PO number matches but one or more supporting fields differ, the result should be classified as:

* **Matched with Discrepancies**
  rather than a clean match.

---

## Updated Match Outcome Rules

### Matched

A record should be marked as **Matched** when:

* the Acumatica PO number is found in the email subject, body, attachment name, attachment content, or email thread; and
* supporting order details do not show material conflicts.

### Matched with Discrepancies

A record should be marked as **Matched with Discrepancies** when:

* the Acumatica PO number matches; but
* one or more supporting fields differ (e.g. quantity, price, delivery date, item lines).

### Possible Match / Needs Review

A record should be marked as **Possible Match / Needs Review** when:

* the PO number is not found directly; but
* the AI identifies strong contextual evidence suggesting the email relates to the PO.

### Not Matched

A record should be marked as **Not Matched** when:

* the Acumatica PO number is not found; and
* there is insufficient supporting evidence to associate the email to the PO.

---

## UI Update Requirement

In the PO ↔ Email Match Review screen, the system must explicitly show:

### PO Number Match Source

A dedicated field or badge showing **where the Acumatica PO number was found**, for example:

* Found in Subject
* Found in Email Body
* Found in Attachment Filename
* Found in Attachment Content
* Found in Prior Email in Thread
* Not Found

This should be visible in the match summary so users can immediately understand why the system linked the email to the PO.

---

## Logging Requirement Update

The audit log for each match attempt should store:

* Acumatica PO number being searched
* Whether a PO number match was found
* Where it was found:

  * subject
  * body
  * attachment filename
  * attachment content
  * thread history
* any supporting fields that matched or conflicted
* final match classification
* confidence score
