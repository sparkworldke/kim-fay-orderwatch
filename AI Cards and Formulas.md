# PRD: AI Chat DB-Driven Insights & Cards

## 1. Feature Name

**AI Chat DB-Driven Insights & Insight Cards**

## 2. Product

**Kim-Fay OrderWatch**

---

## 3. Objective

Improve the OrderWatch AI Assistant so that chat prompts return accurate, useful business insights from the database instead of only reading the current page context.

The AI must use background data formulas inside `AiController` and supporting service classes to analyse Orders, Customers, Emails, Sales Orders, Predictions, and Match Results.

Responses should include rich insight cards, summaries, comparisons, risks, and recommended actions.

---

## 4. Problem Statement

The current AI Assistant gives limited responses because it appears to rely mainly on the current page context. For prompts like:

* “Summarise today’s orders”
* “Show risky orders”
* “Compare this week vs last week”
* “Which customers are declining?”
* “Show unmatched emails”
* “What needs attention today?”

the assistant should not simply respond with a generic message. It should query the database, apply business logic, and return structured insight cards.

---

## 5. Required Behaviour

When a user sends a chat prompt, the AI must:

1. identify the user’s intent
2. determine which database domains are needed
3. query the relevant DB tables in the background
4. calculate the required metrics using formulas
5. generate a clear business summary
6. return multiple cards where useful
7. save the prompt, response, data sources, formulas used, and logs

The AI should not be limited to the current page data unless the user specifically asks for “this page” or “selected record”.

---

## 6. Data Domains to Support

The AI Assistant should analyse data from:

* Orders
* Customers
* Emails
* Acumatica Sales Orders
* PO/email match results
* Sales order match results
* Predictions
* Cron job runs
* Email sync logs

---

## 7. AiController Requirement

Update `AiController` so that it acts as an orchestration layer, not a simple prompt responder.

`AiController` should:

* receive the user prompt
* classify the prompt intent
* identify required data domains
* call the correct insight service
* run formulas and aggregations
* prepare structured context for the AI model
* return response text plus insight cards
* log the full interaction

---

## 8. Suggested Backend Services

Create or improve the following services:

* `AiIntentClassifierService`
* `OrderInsightService`
* `CustomerInsightService`
* `EmailInsightService`
* `AcumaticaSalesOrderInsightService`
* `PredictionInsightService`
* `MatchInsightService`
* `CronInsightService`
* `AiResponseCardBuilder`
* `AiPromptLogService`

---

## 9. Formula Requirements

The AI must use backend formulas for reliable numbers, not allow the model to guess calculations.

Supported formulas should include:

## Orders

* total orders
* total order value
* captured orders
* uncaptured orders
* capture rate
* average order value
* revenue at risk
* critical orders
* late orders
* SLA breached orders

## Customers

* active customers
* inactive customers
* days since last order
* customer order frequency
* customer revenue trend
* top customers
* declining customers
* churn-risk customers

## Emails

* emails received
* processed emails
* skipped emails
* unmatched emails
* emails awaiting review
* emails by folder
* emails by sender
* emails with PO detected
* emails skipped by reason

## Acumatica Sales Orders

* sales orders created
* sales orders updated
* matched sales orders
* unmatched sales orders
* sales order value
* customer PO number coverage

## Predictions

* predicted orders
* predicted revenue
* risk scores
* churn probability
* growth opportunity score
* predicted vs actual variance

---

## 10. Comparison Requirements

The AI must support comparisons using formulas for:

* Day by Day
* Today vs Yesterday
* Week on Week
* Month on Month
* MTD vs Prior MTD
* YTD vs Prior YTD
* Custom Date Range vs Previous Equivalent Period

For each comparison, the backend must calculate:

* current period value
* previous period value
* absolute variance
* percentage variance
* direction of change
* key drivers where available

---

## 11. Insight Card Requirements

The AI response should support multiple card types.

## Required Card Types

### KPI Card

Used for single metrics.

Example:

* Orders Today: 124
* Capture Rate: 86%
* Revenue at Risk: KES 1.2M

### Comparison Card

Used for period comparisons.

Example:

* This Week vs Last Week
* MTD vs Prior MTD
* YTD vs Last YTD

### Risk Card

Used for exceptions and warnings.

Example:

* 12 unmatched emails
* 7 sales orders without email match
* 4 customers at churn risk

### Customer Card

Used for customer insights.

Example:

* Top Customer: Naivas
* Declining Customer: Carrefour
* Inactive Customer: Quickmart

### Match Card

Used for PO/email/Sales Order matching.

Example:

* 18 matched
* 5 matched with discrepancies
* 9 needs review

### Action Card

Used for recommendations.

Example:

* Review unmatched Naivas POs
* Check skipped emails due to sender_not_allowed
* Re-run auto-match cron job

---

## 12. Example AI Response Structure

For prompt:

**“Summarise today’s orders”**

The response should return:

### Summary Text

“Today, OrderWatch captured strong order activity with 124 orders received and a capture rate of 86%. The main issue is 17 uncaptured orders, representing KES 1.2M revenue at risk.”

### Cards

* Orders Received: 124
* Orders Captured: 107
* Capture Rate: 86%
* Revenue at Risk: KES 1.2M
* Critical Orders: 6
* Top Customer: Naivas
* Unmatched Emails: 12

### Recommended Actions

* Review 17 uncaptured orders
* Check 12 unmatched PO emails
* Prioritise 6 critical orders

---

## 13. Frontend Requirements

Update the AI Assistant chat UI to display:

* normal chat response text
* multiple insight cards below the response
* source badges showing data domains used
* formula labels where useful
* follow-up prompt chips
* links to relevant records/pages

Example source badges:

* Orders
* Customers
* Emails
* Acumatica
* Predictions

---

## 14. Suggested API Response Format

`POST /api/ai-assistant/chat` should return structured data like:

```json id="q4iz7n"
{
  "message": "Today’s orders are performing well, but several records need attention.",
  "cards": [
    {
      "type": "kpi",
      "title": "Orders Today",
      "value": 124,
      "subtitle": "Received today"
    },
    {
      "type": "kpi",
      "title": "Capture Rate",
      "value": "86%",
      "subtitle": "107 of 124 captured"
    },
    {
      "type": "risk",
      "title": "Revenue at Risk",
      "value": "KES 1.2M",
      "severity": "high"
    }
  ],
  "sources": ["orders", "emails", "matches"],
  "actions": [
    {
      "label": "View Uncaptured Orders",
      "url": "/orders?status=uncaptured"
    }
  ]
}
```

---

## 15. Logging Requirements

Every AI request must log:

* user id
* user role
* prompt
* interpreted intent
* data domains used
* formulas used
* DB query scope
* filters applied
* AI response
* cards returned
* response time
* success or failure status
* error message if any

---

## 16. Admin Requirements

Add an admin view for AI usage logs.

The admin should see:

* prompt history
* user
* intent
* data domains used
* formulas used
* cards generated
* response status
* error logs
* response latency

---

## 17. Acceptance Criteria

This feature is complete when:

* AI chat can answer from DB data, not only current page context
* `AiController` routes prompts to insight services
* formulas calculate business metrics before AI response generation
* AI returns text plus multiple insight cards
* comparisons work for DoD, WoW, MoM, MTD, and YTD
* logs save prompt, response, formulas, cards, and data domains used
* frontend displays cards clearly under the chat response

---

## 18. Final Requirement Summary

The OrderWatch AI Assistant must be upgraded from a basic prompt responder into a DB-driven AI Insights Assistant.

It must use `AiController` and backend insight services to analyse business data in the background, calculate metrics using formulas, return richer responses with multiple insight cards, and fully log every AI request and result.
