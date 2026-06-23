# PRD: Daily Management OrderWatch Notification Email

## Feature: 8:00 AM AI-Powered Daily Operations & Orders Brief

---

# 1. Feature Name

**Daily Management Notification Email**

---

# 2. Product

**Kim-Fay OrderWatch**
Internal order monitoring, email ingestion, Acumatica reconciliation, AI insights, and revenue protection dashboard.

---

# 3. Objective

Build an automated **daily management email** sent **every morning at 8:00 AM** that summarizes the **previous day’s operational order performance**, provides **Month-to-Date (MTD) context**, compares performance against the **day before yesterday**, and uses **AI-generated commentary** to highlight what management should know, where the risks are, and what needs improvement.

The report must be **standardized**, **consistent**, and designed for management visibility — not just raw operational metrics.

---

# 4. Problem Statement

Management and operations leadership need a concise but high-value daily briefing that answers:

* How did orders perform yesterday?
* How many orders were completed / captured / outstanding?
* Are we improving or declining vs the day before?
* What is our MTD position?
* What is the completion rate and where is revenue at risk?
* Which customers / accounts / teams are driving performance or causing issues?
* What operational issues need immediate attention today?

Currently, this requires manual extraction or dashboard review. OrderWatch should automatically generate and email this insight every morning in a standardized format.

---

# 5. Goals

## Primary Goals

* Send an **automated daily management email every morning at 8:00 AM**
* Summarize **previous day performance**
* Include **MTD context**
* Compare **yesterday vs day before yesterday**
* Use **AI to generate commentary, trends, risks, and recommended actions**
* Present a standardized management-ready email layout
* Save report logs and delivery logs in OrderWatch

## Secondary Goals

* Reduce manual daily reporting
* Improve management visibility into order execution performance
* Surface risks early (uncaptured orders, unmatched emails, delayed orders, poor completion rates)
* Create a reusable daily reporting framework for executives and operational managers

---

# 6. Non-Goals

This phase will **not** include:

* customer-facing notifications
* WhatsApp or SMS delivery in the first release
* free-form ad hoc report builder
* writeback into Acumatica from the report
* real-time streaming updates during the day
* OCR-specific reporting logic

---

# 7. Audience / Recipients

Primary recipients may include:

* Managing Director / Directors
* Customer Service Manager
* Sales Operations
* Commercial / Key Account leadership
* Administrator / System owner

The recipient list must be configurable in Admin.

---

# 8. Schedule Requirement

## Delivery Time

The report must be generated and emailed **every day at 8:00 AM**.

## Reporting Window

The report should cover **the previous calendar day**.

Example:

* Email sent at **8:00 AM on 24 June**
* Main daily performance section should report on **23 June**

## Comparison Window

The report should compare:

* **Yesterday** vs **Day Before Yesterday**

Example:

* Yesterday = 23 June
* Day before yesterday = 22 June

## MTD Window

The report should also include:

* **Month-to-Date totals up to yesterday**
* and where relevant, **MTD vs Prior MTD** comparison

---

# 9. High-Level Report Structure

The daily management email must follow a standardized structure.

## Standard Email Sections

1. **Executive Summary / AI Insight Header**
2. **MTD Orders Snapshot**
3. **Yesterday’s Order Performance**
4. **Completed / Captured Yesterday**
5. **Comparison vs Day Before Yesterday**
6. **Completion Rate / Capture Rate / Operational Efficiency**
7. **Revenue at Risk / Outstanding Orders / Critical Exceptions**
8. **Top Customer / Account Highlights**
9. **What Needs Improvement Today**
10. **Optional Drill-Down Links to OrderWatch**

---

# 10. Core Report Content Requirements

# 10.1 Executive Summary (AI-Generated)

At the top of the email, include a short AI-generated management summary.

This should answer:

* What happened yesterday?
* Was performance better or worse than the previous day?
* What is the biggest positive?
* What is the biggest concern?
* What management should focus on today

Example:

> Yesterday OrderWatch recorded solid order intake, but completion performance weakened. Orders received increased by 12% versus the day before, driven mainly by Naivas and Carrefour, but the completion rate fell from 88% to 79%, leaving KES 2.1M in outstanding value. Management attention should focus on uncaptured key-account orders and unresolved email-to-order matches.

---

# 10.2 MTD Orders Snapshot

The report must include a Month-to-Date section showing cumulative performance up to yesterday.

## Required MTD metrics

* MTD Orders Received
* MTD Orders Completed / Captured
* MTD Completion Rate / Capture Rate
* MTD Outstanding Orders
* MTD Revenue / Order Value
* MTD Revenue at Risk
* MTD Critical Orders count
* MTD unmatched / unresolved order-related exceptions if relevant

## Optional MTD comparison

Where available, also show:

* **MTD vs Prior MTD**
* variance amount
* variance %

---

# 10.3 Yesterday’s Orders

The report must clearly show the previous day’s order performance.

## Required metrics

* Orders Received Yesterday
* Total Order Value Yesterday
* Orders Completed / Captured Yesterday
* Outstanding / Uncaptured Orders Yesterday
* Completion / Capture Rate Yesterday
* Critical Orders Yesterday
* Revenue at Risk Yesterday
* Late / unresolved order issues if relevant

---

# 10.4 Completed Yesterday

A specific section must highlight **completed/captured performance**.

## Required metrics

* Orders completed yesterday
* % of yesterday’s received orders completed
* value of completed orders
* outstanding balance / remaining uncaptured value
* completion rate trend vs day before yesterday

This section should help management understand whether the business is not just receiving orders, but actually processing them effectively.

---

# 10.5 Comparison vs Day Before Yesterday

The report must compare **yesterday** against **the day before yesterday**.

## Comparison metrics should include:

* Orders Received
* Order Value
* Orders Completed / Captured
* Completion Rate
* Outstanding Orders
* Revenue at Risk
* Critical Orders
* Email / matching exceptions if relevant

For each metric, show:

* Yesterday value
* Day-before-yesterday value
* absolute variance
* % variance
* directional indicator (up/down)

Example:

| Metric           | Yesterday | Day Before |    Change | % Change |
| ---------------- | --------: | ---------: | --------: | -------: |
| Orders Received  |       128 |        116 |       +12 |   +10.3% |
| Completed Orders |       101 |        103 |        -2 |    -1.9% |
| Completion Rate  |     78.9% |      88.8% |  -9.9 pts |   -11.1% |
| Revenue at Risk  |  KES 2.1M |   KES 1.4M | +KES 0.7M |     +50% |

---

# 10.6 Completion Rate / Operational Efficiency

Management will want to know whether operations are converting incoming orders into completed/captured orders effectively.

## Required operational metrics

* Completion Rate Yesterday
* Completion Rate Day Before Yesterday
* MTD Completion Rate
* Capture Rate Yesterday
* Capture Rate MTD
* average processing gap / backlog where available
* count of orders still pending action

## AI commentary should explain:

* whether completion performance improved or worsened
* whether the issue is volume-driven or execution-driven
* whether a specific customer / team / branch is dragging the rate down

---

# 10.7 Revenue at Risk / Outstanding Orders / Exceptions

The report must include a management risk section.

## Required risk metrics

* Revenue at Risk Yesterday
* Outstanding / Uncaptured Orders count
* Critical Orders count
* Unmatched email / PO / Sales Order count where relevant
* Orders pending manual review
* orders with discrepancies
* skipped or problematic email ingestion if materially relevant

This section should help management identify where immediate intervention is needed.

---

# 10.8 What Management Would Be Looking For

The report must not just dump metrics; it should explicitly answer the management questions that matter most.

## Management focus areas to include

1. **Are orders growing or declining?**
2. **Are we completing what we receive?**
3. **How much value is still at risk?**
4. **Which customers/accounts are driving performance?**
5. **Which customers/accounts are underperforming or causing issues?**
6. **Are there operational bottlenecks or backlog risks?**
7. **Did email/order matching issues affect order processing?**
8. **What should the team improve today?**

---

# 11. AI Insight Requirements

The report must use AI to generate management commentary, not just raw metrics.

## AI must generate:

* an executive summary
* performance commentary
* trend interpretation
* risk highlights
* top positive drivers
* top negative drivers
* “what needs improvement today”
* optional recommended actions

## AI should be grounded on actual OrderWatch metrics only

The AI must not invent figures. It should receive structured metrics from the backend and generate commentary from that data.

---

# 12. “What We Need to Improve” Section

The report must contain a standardized action-oriented section.

## This section should highlight:

* completion rate deterioration
* high outstanding order count
* rising revenue at risk
* delayed capture
* key account underperformance
* unmatched emails or PO/Sales Order issues
* customer-specific risks
* backlog / operational bottlenecks

Example:

### What Needs Improvement Today

* Completion rate dropped 9.9 points vs the previous day and should be recovered above 85%
* 17 orders remain uncaptured, with KES 2.1M still at risk
* Naivas had the largest outstanding value and should be prioritized
* 6 orders remain in review due to email/Sales Order matching issues
* Revenue conversion is lagging order intake growth

---

# 13. Recommended Standardized Email Layout

## Subject Line Format

Examples:

* **OrderWatch Daily Brief – 23 Jun 2026**
* **OrderWatch Daily Management Report – Yesterday Performance & MTD**
* **OrderWatch Morning Brief – Orders, Completion & Risk Update**

---

## Email Body Structure

### 1. Header

* Date of report
* Reporting window
* generated at timestamp

### 2. Executive Summary

AI-written summary paragraph

### 3. MTD Snapshot Table

Key MTD KPIs

### 4. Yesterday Performance Table

Key yesterday KPIs

### 5. Comparison vs Day Before Yesterday

Comparison table

### 6. Operational Efficiency / Completion Section

Completion/capture commentary and KPIs

### 7. Risk & Exceptions Section

Revenue at risk, outstanding orders, critical issues

### 8. Top Drivers / Account Highlights

Best and worst performing accounts

### 9. What Needs Improvement Today

AI-generated action points

### 10. Footer

* generated by OrderWatch
* optional “View full dashboard” link

---

# 14. Suggested Metrics for the Standardized Email

## A. MTD Section

* MTD Orders Received
* MTD Orders Completed
* MTD Completion Rate
* MTD Revenue
* MTD Revenue at Risk
* MTD Critical Orders

## B. Yesterday Section

* Orders Received Yesterday
* Order Value Yesterday
* Orders Completed Yesterday
* Completion Rate Yesterday
* Revenue at Risk Yesterday
* Outstanding Orders Yesterday

## C. Comparison Section

* Yesterday vs Day Before Yesterday for:

  * Orders Received
  * Order Value
  * Orders Completed
  * Completion Rate
  * Revenue at Risk

## D. Management Insight Section

* top positive customer / account
* top risk customer / account
* largest source of revenue at risk
* operational bottleneck summary

---

# 15. Data Sources

The report should pull from OrderWatch-approved datasets such as:

* Orders
* Acumatica Sales Orders
* Order capture / completion statuses
* Email-to-order match status
* PO/email/Sales Order matching results
* customer/account metadata
* predictions / risk scores where relevant
* cron job / sync status if materially relevant

---

# 16. Backend Architecture Requirements

## 16.1 Daily Report Generator Service

Create a service such as:

### `DailyManagementReportService`

Responsible for:

* calculating yesterday metrics
* calculating MTD metrics
* calculating day-before-yesterday comparison metrics
* assembling structured data for the AI summary
* returning the final report payload

---

## 16.2 AI Commentary Service

Create a service such as:

### `DailyManagementInsightService`

Responsible for:

* receiving structured KPI payloads
* generating executive summary text
* generating management commentary
* generating “what needs improvement today” section

---

## 16.3 Email Delivery Service

Create a service such as:

### `DailyReportMailerService`

Responsible for:

* building the standardized email template
* sending the email to configured recipients
* saving send status logs
* handling failures / retries

---

## 16.4 Scheduler / Cron Service

Create a scheduled job such as:

### `SendDailyManagementReportJob`

Responsible for:

* running every day at 8:00 AM
* calling the report generator
* generating AI commentary
* sending the email
* logging results

---

# 17. Scheduler Requirement

The report must be sent **daily at 8:00 AM**.

Recommended Laravel schedule:

* `dailyAt('08:00')`

The system should also support:

* manual test send
* resend last report
* disable/enable report
* configurable recipients

---

# 18. Admin UI Requirements

## Add a new Admin section:

# **Daily Notifications**

This page should allow admins to manage the daily management email.

## Required settings

* report enabled / disabled
* send time
* recipient list
* subject format
* include AI insights yes/no
* include MTD comparison yes/no
* include detailed customer breakdown yes/no
* test send button
* last sent status
* last sent timestamp

---

# 19. Logging Requirements

## 19.1 Daily Report Run Log

Save:

* report_run_id
* report date
* scheduled send time
* generated_at
* sent_at
* status
* duration
* recipient count
* report type (`daily_management_email`)
* whether AI commentary succeeded
* whether delivery succeeded
* error summary if failed

## 19.2 Metric Snapshot Log

Save the KPI payload used for the report:

* yesterday metrics
* day-before-yesterday metrics
* MTD metrics
* comparison metrics
* risk metrics
* top customer / risk drivers

## 19.3 Email Delivery Log

Save:

* recipients
* subject
* send status
* provider response if available
* retry count

---

# 20. Suggested Database Tables

## `daily_report_configs`

Stores configuration for the report.

Fields:

* id
* name
* is_enabled
* send_time
* timezone
* recipients_json
* subject_template
* include_ai_insights
* include_comparison
* include_mtd
* include_customer_highlights
* created_at
* updated_at

---

## `daily_report_runs`

Stores each daily report execution.

Fields:

* id
* report_config_id
* report_date
* started_at
* completed_at
* sent_at
* status
* ai_status
* delivery_status
* recipient_count
* duration_ms
* error_summary
* payload_json
* created_at
* updated_at

---

## `daily_report_delivery_logs`

Stores email delivery details.

Fields:

* id
* daily_report_run_id
* recipient_email
* delivery_status
* provider_message_id
* error_message
* created_at

---

# 21. Example Report Narrative

Example management-style output:

### Executive Summary

Yesterday OrderWatch recorded 128 orders worth KES 14.6M, up 10.3% in volume versus the day before. However, completion performance weakened, with only 101 orders completed and completion rate falling to 78.9% from 88.8%. This has pushed revenue at risk up to KES 2.1M. Naivas and Carrefour drove order intake growth, but Naivas also accounted for the largest outstanding value. Management focus today should be on clearing uncaptured key-account orders and resolving high-value exceptions.

### What Needs Improvement Today

* Recover completion rate above 85%
* Clear 17 outstanding orders worth KES 2.1M
* Prioritise Naivas and Carrefour uncaptured orders
* Resolve email-to-order matching issues holding up 6 orders
* Monitor MTD completion trend, which is now behind target

---

# 22. Acceptance Criteria

This feature is complete when:

* OrderWatch sends a daily email every morning at 8:00 AM
* the report covers the previous day
* the report includes MTD metrics
* the report compares yesterday vs day before yesterday
* the report includes completion / capture performance
* the report includes revenue at risk / outstanding orders / critical exceptions
* the report includes AI-generated executive insights and improvement recommendations
* admins can manage recipients and settings in Admin
* report runs and delivery are fully logged

---

# 23. Final Requirement Summary

OrderWatch must include a **Daily Management Notification Email** that sends every morning at **8:00 AM** and summarizes the **previous day’s order performance**, **MTD order position**, **comparison vs the day before yesterday**, **completion/capture performance**, **revenue at risk**, and **what management needs to improve today**.

The email must use **AI-generated insights grounded in OrderWatch data** and follow a **standardized executive-friendly format** suitable for directors, operations managers, and leadership.
