# PRD: Mailbox Folder & Rule Detection for OrderWatch Email Sync

## 1. Feature Name

**Mailbox Folder & Rule Detection**

## 2. Product

**Kim-Fay OrderWatch**
Internal order monitoring, PO matching, email ingestion, and revenue protection dashboard.

---

## 3. Objective

Build a feature that allows OrderWatch to detect, list, validate, and use existing Outlook mailbox folders and business rules during email sync.

The system must support inbox folders such as **Naivas POs**, customer-specific PO folders, and existing mailbox rules so that emails are not skipped incorrectly when they are valid order-related emails.

The feature must also allow users to configure or attach an existing rule name to a mailbox sync setup.

---

## 4. Problem Statement

OrderWatch currently skips some emails because they fail the sender allow-list validation.

Example log:

```json
{
  "sync_run_id": 5,
  "mailbox_id": 1,
  "message_id": "AAMkADVmNmRkYjI3LWM4YzAtNGIxMS05ODA4LTdkMzQ4MTkxNTFjOQBGAAAAAAD6bCc4hxarTqKnuLVcjj8MBwDoGLZk4hSjTazTLUzANu8PAAAAAAEMAADoGLZk4hSjTazTLUzANu8PAAZB45fqAAA=",
  "outcome": "skipped",
  "reason": "sender_not_allowed",
  "attempts": 1,
  "duration_ms": 0
}
```

This can happen even when the email is valid because:

* the email has already been moved into a valid Outlook folder such as **Naivas POs**
* the sender is not on the allowed list but the folder indicates the email is order-related
* existing Outlook rules are already organizing order emails
* OrderWatch is only checking inbox sender rules and not checking folder context
* the system does not currently allow mapping an existing Outlook rule name to OrderWatch sync logic

OrderWatch needs a safer and more flexible email ingestion rule engine that checks both **folder location** and **rule configuration** before skipping emails.

---

## 5. Goals

The feature must:

* detect and display existing Outlook folders
* support syncing emails from selected folders, not only the inbox
* detect or allow manual entry of existing Outlook rule names
* allow folder-based email acceptance rules
* prevent valid PO emails from being skipped only because the sender is not allow-listed
* improve visibility into why an email was accepted or skipped
* save full logs for folder checks, rule checks, and skip decisions

---

## 6. Non-Goals

This phase will not:

* automatically create Outlook rules inside Microsoft 365 unless added later
* modify user mailbox folders without explicit permission
* delete, archive, or move emails automatically
* override all sender security controls without configuration
* process unrelated mailbox folders by default
* sync every email in the mailbox without filters

---

## 7. Users / Roles

Primary users:

* Administrator
* Customer Service Manager
* Sales Operations
* IT / System Admin

Secondary users:

* Customer Service Agent, view-only or limited access

---

## 8. Core Requirement Summary

OrderWatch must check the following before deciding whether to process or skip an email:

1. Is the email in an approved mailbox folder?
2. Does the folder name match an approved order folder, e.g. **Naivas POs**?
3. Does the email match an existing configured rule name?
4. Is the sender allowed?
5. Does the subject, body, or attachment contain a valid PO number?
6. Does the email appear to be order-related based on configured matching rules?

An email should not be skipped only because of `sender_not_allowed` if it is located in a trusted PO folder or matches a configured rule.

---

## 9. Functional Requirements

## 9.1 Folder Discovery

The system must connect to the mailbox and retrieve available Outlook folders.

The folder discovery should include:

* Inbox
* Inbox subfolders
* customer-specific PO folders
* folders such as:

  * Naivas POs
  * Carrefour POs
  * Quickmart POs
  * Chandarana POs
  * General POs
  * Orders
  * Purchase Orders
* folder ID
* folder display name
* parent folder
* unread count where available
* total item count where available
* last synced date
* whether the folder is enabled for OrderWatch sync

---

## 9.2 Folder Selection for Sync

The mailbox settings page must allow users to select which folders OrderWatch should sync.

Required controls:

* view available folders
* enable or disable folder sync
* label a folder as order-related
* assign folder to a customer or account where applicable
* set sync priority
* set folder-specific filters

Example:

| Folder Name   | Enabled | Customer Mapping | Sync Type |
| ------------- | ------- | ---------------- | --------- |
| Inbox         | Yes     | General          | Standard  |
| Naivas POs    | Yes     | Naivas           | PO Folder |
| Carrefour POs | Yes     | Carrefour        | PO Folder |
| Promotions    | No      | N/A              | Ignore    |

---

## 9.3 Existing Rule Name Configuration

The system must add an option for users to add or select an **Existing Rule Name** for a mailbox or folder configuration.

Required field:

* **Existing Rule Name**

Example:

* `Move Naivas POs to Folder`
* `Naivas PO Rule`
* `Customer PO Sorting`
* `Order Emails - Key Accounts`

The rule name should be stored in OrderWatch and used as metadata for the sync configuration.

The system should allow:

* adding a rule name manually
* linking a rule name to a folder
* showing the rule name in mailbox configuration
* logging the rule name when emails are processed
* using the rule name as supporting evidence for accepting an email

---

## 9.4 Rule Detection / Rule Mapping

Where technically available, the system should attempt to detect mailbox rules from Microsoft Graph.

If automatic rule retrieval is not available or permission is not granted, the system must still allow manual entry of existing rule names.

The rule mapping should include:

* rule name
* mailbox ID
* related folder
* customer/account mapping
* enabled status
* notes
* created by
* updated by

---

## 9.5 Email Acceptance Logic

The email sync engine must update its decision logic.

### Current issue

Emails can be skipped due to:

```text
sender_not_allowed
```

### Updated logic

Before skipping due to sender validation, the system must check:

1. Is the email located inside an approved order folder?
2. Is the email located inside a customer-mapped PO folder?
3. Does the folder have an existing rule name configured?
4. Does the email contain a PO number in subject, body, attachment filename, or attachment content?
5. Does the email match an approved customer/folder mapping?

If yes, the email should be accepted for processing or flagged for review instead of being skipped.

---

## 9.6 Updated Skip / Process Decision Rules

### Process Email

The system should process the email if:

* sender is allowed; or
* email is in an approved PO folder; or
* folder is mapped to a customer account; or
* configured existing rule name indicates the folder is trusted; or
* PO number is detected in subject, body, attachment name, or attachment content

### Flag for Review

The system should flag the email for review if:

* sender is not allowed; but
* email is in a trusted folder; or
* folder is mapped to a customer but the PO number is missing; or
* folder/rule context suggests it is a PO but key fields are incomplete

### Skip Email

The system should only skip the email if:

* sender is not allowed; and
* folder is not trusted; and
* no configured rule applies; and
* no PO number is detected; and
* email has no order-related indicators

---

## 10. UI Requirements

## 10.1 Mailbox Settings Page

Add a mailbox settings section called:

**Folders & Rules**

This section must show:

* connected mailbox
* folder discovery status
* discovered folders
* selected folders for sync
* existing rule name field
* customer/account mapping
* last sync status
* last rule check status

---

## 10.2 Folder List UI

The UI must display folders in a clear table.

Required columns:

* Folder Name
* Parent Folder
* Email Count
* Enabled for Sync
* Customer Mapping
* Existing Rule Name
* Trust Level
* Last Synced
* Actions

Actions:

* Enable Sync
* Disable Sync
* Map Customer
* Add/Edit Existing Rule Name
* Test Folder
* View Recent Emails

---

## 10.3 Rule Name Input

Add an input field:

**Existing Rule Name**

Helper text:

> Enter the Outlook rule name that already moves or identifies these emails, for example “Naivas PO Rule”.

The field should support:

* free text entry
* edit
* remove
* save
* display in logs

---

## 10.4 Email Sync Log UI

Update the sync log screen to show folder and rule context.

Required columns:

* Timestamp
* Mailbox
* Folder Name
* Existing Rule Name
* Sender
* Subject
* Outcome
* Reason
* Decision Source
* Attempts
* Duration

Example:

| Outcome   | Reason                                | Decision Source        |
| --------- | ------------------------------------- | ---------------------- |
| processed | folder_trusted                        | Naivas POs             |
| review    | sender_not_allowed_but_folder_trusted | Naivas POs + Rule Name |
| skipped   | sender_not_allowed_no_folder_match    | Inbox                  |

---

## 11. Logging Requirements

Every email sync decision must log:

* sync_run_id
* mailbox_id
* message_id
* internet_message_id where available
* folder_id
* folder_name
* parent_folder_name
* existing_rule_name
* sender_email
* sender_domain
* subject
* outcome
* reason
* decision_source
* PO number detected yes/no
* PO number source:

  * subject
  * body
  * attachment filename
  * attachment content
* attempts
* duration_ms
* created_at

---

## 12. Suggested New Log Reasons

Add the following decision reasons:

### Processed

* `sender_allowed`
* `folder_trusted`
* `folder_customer_mapped`
* `existing_rule_trusted`
* `po_number_detected`
* `attachment_po_detected`

### Review

* `sender_not_allowed_but_folder_trusted`
* `sender_not_allowed_but_rule_trusted`
* `folder_trusted_but_po_missing`
* `customer_folder_but_sender_unknown`

### Skipped

* `sender_not_allowed_no_folder_match`
* `folder_not_enabled`
* `no_po_indicator`
* `folder_ignored`
* `rule_disabled`

---

## 13. Backend Requirements

## 13.1 Suggested Database Tables

### `mailbox_folders`

Stores discovered mailbox folders.

Fields:

* id
* mailbox_id
* external_folder_id
* display_name
* parent_folder_id
* parent_display_name
* total_item_count
* unread_item_count
* is_sync_enabled
* is_order_folder
* customer_id
* trust_level
* last_synced_at
* created_at
* updated_at

---

### `mailbox_rules`

Stores configured or discovered mailbox rules.

Fields:

* id
* mailbox_id
* folder_id
* existing_rule_name
* external_rule_id nullable
* customer_id nullable
* is_enabled
* trust_level
* notes
* created_by
* updated_by
* created_at
* updated_at

---

### `email_sync_decisions`

Stores detailed email sync decisions.

Fields:

* id
* sync_run_id
* mailbox_id
* message_id
* internet_message_id
* folder_id
* folder_name
* existing_rule_name
* sender_email
* sender_domain
* subject
* outcome
* reason
* decision_source
* po_number_detected
* po_number_source
* attempts
* duration_ms
* raw_context_json
* created_at

---

## 14. Backend Services

### `MailboxFolderDiscoveryService`

Responsible for:

* fetching mailbox folder list
* saving folder metadata
* updating counts
* detecting new folders
* marking removed folders as inactive

### `MailboxRuleMappingService`

Responsible for:

* saving existing rule names
* linking rules to folders
* retrieving rules where supported
* validating rule-folder mapping

### `EmailSyncDecisionService`

Responsible for:

* evaluating sender rules
* evaluating folder trust
* evaluating existing rule names
* checking PO number indicators
* deciding process / review / skip
* writing detailed decision logs

### `MailboxSyncService`

Responsible for:

* syncing enabled folders
* fetching messages by folder
* handing each message to the decision service
* passing accepted messages to PO/email matching pipeline

---

## 15. API Requirements

Suggested endpoints:

### Folders

* `GET /api/mailboxes/{mailbox}/folders`
* `POST /api/mailboxes/{mailbox}/folders/discover`
* `PATCH /api/mailbox-folders/{folder}`
* `POST /api/mailbox-folders/{folder}/test`

### Rules

* `GET /api/mailboxes/{mailbox}/rules`
* `POST /api/mailbox-rules`
* `PATCH /api/mailbox-rules/{rule}`
* `DELETE /api/mailbox-rules/{rule}`

### Logs

* `GET /api/email-sync-decisions`
* `GET /api/email-sync-decisions/{id}`

---

## 16. Microsoft Graph Requirements

The system should retrieve messages from selected folders, not only the inbox.

Required Graph capabilities:

* list mailbox folders
* list child folders
* fetch messages from selected folder
* inspect message subject/body/sender
* inspect attachment metadata
* read attachment content where supported and permissioned

The sync engine should support Graph folder IDs and store them against OrderWatch mailbox folder records.

---

## 17. Example Workflow: Naivas POs Folder

1. Admin connects Outlook mailbox
2. Admin opens **Folders & Rules**
3. System discovers folders
4. System finds folder named **Naivas POs**
5. Admin enables sync for **Naivas POs**
6. Admin maps folder to customer **Naivas**
7. Admin enters existing rule name: **Naivas PO Rule**
8. Sync job fetches messages from the Naivas POs folder
9. Email sender is not allow-listed
10. Decision engine checks folder context
11. Folder is trusted and mapped to Naivas
12. Email is processed or flagged for review instead of skipped
13. Log records:

```json
{
  "outcome": "review",
  "reason": "sender_not_allowed_but_folder_trusted",
  "folder_name": "Naivas POs",
  "existing_rule_name": "Naivas PO Rule",
  "decision_source": "folder_trusted"
}
```

---

## 18. Acceptance Criteria

The feature is complete when:

* users can discover mailbox folders
* users can enable or disable folder sync
* users can map folders to customers
* users can add an existing rule name
* sync engine can process selected folders
* emails in trusted PO folders are not automatically skipped due to `sender_not_allowed`
* all email decisions include folder and rule context in logs
* the system can distinguish processed, review, and skipped outcomes
* users can view folder/rule context in the sync log UI

---

## 19. Final Requirement Summary

OrderWatch must include a **Mailbox Folder & Rule Detection** feature that allows the system to discover Outlook folders, support customer-specific PO folders such as **Naivas POs**, configure existing Outlook rule names, and use folder/rule context during email sync decisions.

The system must no longer skip valid PO emails solely because the sender is not allow-listed when the email exists in a trusted PO folder or matches a configured rule.

All folder checks, rule checks, PO number checks, and email sync decisions must be saved in detailed logs for audit and troubleshooting.
