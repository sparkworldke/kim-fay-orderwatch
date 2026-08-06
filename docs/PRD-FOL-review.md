My understanding is that the FOL module needs to distinguish between the free equipment being issued and the products used to justify that equipment.

**Inventory Classification**
Every eligible inventory item should have an FOL classification:

1. **FOL Item**
   - A dispenser or equipment item issued to the customer free of charge.
   - Selected as the requested asset on the FOL application.
   - Its normal sales history should not determine approval.
   - Track quantity issued, cost, installation, custody, and return status.

2. **FOL Consumable**
   - A product the customer purchases and uses with the dispenser.
   - Used to calculate the customer’s historical sales and consumption.
   - Examples could include tissue, soap, sanitizer, refills, or compatible products.
   - These items support the commercial case for approving a free dispenser.

3. **Both**
   - Safety-net classification for an inventory item that can act as either an issued FOL item or a consumable.
   - It appears in both selection contexts.
   - The request must still specify how it is being used in that particular application to avoid counting the free item as paid consumption.

**Proposed Request Flow**
```text
Customer
→ Select FOL dispenser/equipment
→ Enter requested quantity
→ Select relevant consumables
→ Review 3-month and 6-month sales
→ Calculate approval indicators
→ Submit for approval
```

The request should contain two separate sections:

### Dispensers Requested

- FOL inventory item
- Description
- Requested quantity
- Unit cost
- Total equipment exposure
- Installation location
- Whether the item is classified as `FOL Item` or `Both`

### Supporting Consumables

- Consumable inventory item
- Description
- Compatibility with the selected dispenser
- Sales quantity over the previous three months
- Sales quantity over the previous six months
- Revenue over three and six months
- Average monthly quantity and revenue
- Whether classified as `FOL Consumable` or `Both`

**Approval Information**
Approvers should see:

- Customer and outlet
- Main account
- Assigned sales consultant
- Requested dispensers and quantities
- Total dispenser cost
- Related consumables
- Three-month sales and volume
- Six-month sales and volume
- Monthly purchasing trend
- Last purchase date
- Existing FOL equipment already assigned to that customer
- Previous rejected, active, returned, or pending FOL requests
- Recommended approval result with the calculation explanation

**Suggested Calculations**
For supporting consumables:

```text
3-month average volume = consumable quantity sold in last 3 complete months / 3

6-month average volume = consumable quantity sold in last 6 complete months / 6

3-month average revenue = consumable net revenue in last 3 complete months / 3

6-month average revenue = consumable net revenue in last 6 complete months / 6
```

The approval screen should show both periods. Three months reflects recent performance, while six months protects against temporary spikes or seasonal ordering.

Credit Notes should reduce consumable revenue and volume where applicable.

**Important Guardrails**
- A dispenser cannot justify its own approval as a consumable in the same request.
- `Both` items require an explicit request-line usage: `dispenser` or `consumable`.
- Only approved FOL classifications appear in selectors.
- Use exact Acumatica inventory IDs.
- Use the exact mapped customer/outlet ID for sales history.
- Exclude cancelled and unreleased documents.
- Prevent duplicated sales when an item appears through multiple categories.
- Snapshot the three- and six-month calculations when submitted so later sales synchronization does not change the approval evidence.
- Show missing or incomplete sales history instead of treating it as zero.
- Check existing dispensers at the customer before approval.
- Record who classified each inventory item and who changed the classification.

The central distinction is: **FOL Items are the free assets being requested; FOL Consumables are the paid products whose historical sales justify the request; Both is an explicitly controlled safety-net classification.**