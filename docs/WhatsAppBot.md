



Develop and implement a WhatsApp using Baileys and Telegram multi-platform chatbot system with the following structured requirements and implementation criteria:

### Core User Identification & Authorization Features
1. Implement persistent storage functionality to save each user's WhatsApp display name during their initial interaction, enabling automatic user identification and chat context synchronization across all future conversations
2. Build a secure multi-channel user authorization workflow:
   - Require new users to verify their identity by submitting their registered WhatsApp number
   - Trigger an OTP (One-Time Password) delivery via SMS or email immediately after the user submits their phone number
   - Grant full chatbot access only after the user successfully enters and validates the received OTP
   - Log all authorization attempts and store verified user profiles with encrypted contact information for security compliance

### Post-Authorization User Guidance
Upon successful verification, provide users with a clear, structured introductory guide that outlines all available chatbot commands and functionalities, including instructions on how to request the following order management features:
- Retrieve a detailed summary of yesterday's customer orders
- Filter order records by specific calendar months (June, July, and other specified periods)
- Access and export order data categorized by the following statuses:
  - BackOrder value tracking and reporting
  - Pending Approval order queues
  - SOS priority order alerts

### Technical Implementation Requirements
- Ensure all user data (including display names, phone numbers, and order histories) is encrypted at rest and in transit to meet data privacy standards
- Implement error handling for OTP delivery failures, invalid verification attempts, and database connection issues
- Add rate limiting to authorization requests to prevent abuse and unauthorized access attempts
- Maintain a sync log to track all user context restoration events for audit and troubleshooting purposes

### Testing & Validation Criteria
- Conduct end-to-end testing of the full authorization flow, verifying OTP delivery across SMS and email channels
- Validate that user WhatsApp names are correctly retrieved and applied to all subsequent chat sessions
- Test all order filtering and status reporting features to ensure accurate data retrieval for all supported query types
- Verify system security by attempting unauthorized access attempts to confirm authorization controls function as intended