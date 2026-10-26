# System Architecture Specification

## 1. Architectural Overview

EspoCRM utilizes a **Single-Page Application (SPA)** frontend coupled with a **RESTful PHP backend** built on a metadata-driven, modular architecture. All custom features developed in this project adhere strictly to the extension paradigm (`custom/` directory and client custom namespaces), ensuring zero core file modifications and seamless upstream upgrade compatibility.

---

## 2. System Data Flow Diagrams

### Diagram A: Smart Reminders & AI-Suggested Timing Flow

```mermaid
sequenceDiagram
    autonumber
    actor User as CRM User
    participant View as smart-reminders.js (Client View)
    participant Ctrl as SmartReminder.php (REST API)
    participant Pref as Preferences Entity
    participant Svc as Service.php (Coordinator)
    participant AI as AiSuggester.php (Groq/OpenRouter)
    participant Rules as RuleEngine.php (Heuristics)
    participant Hook as SmartReminderHook.php (beforeSave)
    participant Saver as Reminder Saver (Core Processor)
    participant DB as MariaDB (reminder table)

    User->>View: Clicks "Smart Timing"
    View->>Ctrl: POST /api/v1/SmartReminder/action/suggestTiming
    Ctrl->>Pref: Resolve User Preferences (Mode, Buffer)
    Ctrl->>Svc: suggestTiming(payload, useAi, enablePrepBuffer)
    alt Mode == AI and Provider Available
        Svc->>AI: suggest(agenda, leadTime, priority)
        AI-->>Svc: AI Timing Recommendations
    else AI Unavailable or Mode == Rules
        Svc->>Rules: evaluate(leadTime, priority, cues, prepBuffer)
        Rules-->>Svc: Deterministic Rule Reminders
    end
    Svc-->>Ctrl: Standardized Reminders + Reason
    Ctrl-->>View: JSON Response (200 OK)
    View->>User: Populates Form + Renders Status Badge
    User->>View: Clicks Save Event
    View->>Hook: Entity beforeSave Triggered
    Hook->>Pref: Validate Assigned User Preference
    Hook->>Saver: Pass Formatted Reminders Array
    Saver->>DB: INSERT into `reminder` (remind_at, type, user_id)
```

---

### Diagram B: AI Conversation Summarizer Flow

```mermaid
sequenceDiagram
    autonumber
    actor Agent as Sales Rep
    participant Activity as Call / Meeting / Task / Email
    participant Hook as ActivityConversationSummary.php (Hook)
    participant JobMgr as Job Scheduler (daemon.php)
    participant Job as ConversationSummarizer.php (Job Worker)
    participant Groq as Groq Cloud API (Primary LLM)
    participant OR as OpenRouter API (Fallback LLM)
    participant DB as Account Entity (aiSummary)

    Agent->>Activity: Save activity linked to Account
    Activity->>Hook: afterSave lifecycle hook
    Hook->>JobMgr: Schedule job 'conversation-summary-{accountId}'
    JobMgr->>Job: Execute job worker asynchronously
    Job->>DB: Query 5 latest activities linked to Account
    alt Primary Provider (Groq)
        Job->>Groq: POST /chat/completions (Prompt with 5 interactions)
        Groq-->>Job: 3-bullet summary
    else Primary Fails / Unconfigured
        Job->>OR: POST /chat/completions (Fallback request)
        OR-->>Job: 3-bullet summary
    end
    Job->>DB: Update Account.aiSummary (skip hooks to avoid recursion)
```

---

### Diagram C: Activities per Account Dashboard Query Optimization

```mermaid
graph TD
    Dashlet["Client Dashlet View"] -->|GET /api/v1/ActivitySummary/action/report?limit=10| Controller["ActivitySummary Controller"]
    Controller --> Report["Report Tool (Report.php)"]
    Report --> ACLCheck{"Check Read ACL (Account, Call, Meeting, Email)"}
    ACLCheck -->|Forbidden| Err403["Throw 403 Forbidden"]
    ACLCheck -->|Allowed| Aggregation["Collect Activity Counts (Call, Meeting, Email) for Last 30 Days"]
    Aggregation --> CountMap["Memory Map: countMap[accountId] = {Call, Meeting, Email}"]
    CountMap --> PreRank["PHP Pre-Ranking: Calculate total per account & arsort()"]
    PreRank --> Prune["Candidate Pruning: Extract Top Candidates (max 20 IDs)"]
    Prune --> AccountQuery["ORM SelectBuilder: SELECT id, name FROM account WHERE id IN (top 20)"]
    AccountQuery --> NameSort["Deterministic Sort: Primary total DESC, Secondary accountName ASC"]
    NameSort --> Slice["array_slice(dataList, 0, limit)"]
    Slice --> JSONResponse["JSON 200 OK Output (Top 10 Ranked Accounts)"]
```

---

## 3. API Endpoints Specification

### 1. `POST /api/v1/SmartReminder/action/suggestTiming`
Suggests optimal reminders and timing lead times based on meeting metadata and user preferences.
- **Request Headers**:
  - `Content-Type: application/json`
  - `Espo-Authorization: <Token>` or CRM session cookie
- **Request Body**:
  ```json
  {
    "entityType": "Meeting",
    "name": "Architecture Review and Slide Deck",
    "description": "Prepare demo and review contract clauses.",
    "dateStart": "2026-10-30 14:00:00",
    "priority": "High",
    "useAi": true,
    "enablePrepBuffer": true
  }
  ```
- **Response (200 OK)**:
  ```json
  {
    "reminders": [
      { "type": "Email", "seconds": 172800 },
      { "type": "Email", "seconds": 86400 },
      { "type": "Popup", "seconds": 7200 }
    ],
    "source": "ai",
    "reason": "High-priority meeting requiring multi-day advance warning and 2h preparation buffer.",
    "leadTimeSeconds": 313200
  }
  ```
- **Error Responses**:
  - `400 Bad Request`: When `entityType` is unsupported or `dateStart` is missing.
  - `403 Forbidden`: When read permission is denied on the entity scope.

### 2. `GET /api/v1/ActivitySummary/action/report`
Retrieves the top-ranked accounts by total activity volume over the last 30 days.
- **Query Parameters**:
  - `limit` (int, optional, default: 10, max: 100): Number of top accounts to return.
- **Response (200 OK)**:
  ```json
  {
    "from": "2026-09-26 14:00:00",
    "dataList": [
      {
        "Call": 15,
        "Meeting": 8,
        "Email": 24,
        "accountId": "653a1f9e",
        "accountName": "Acme Technologies",
        "total": 47
      }
    ]
  }
  ```

---

## 4. Database Schema & Metadata Extensions

All schema changes are declared via JSON metadata files in `custom/Espo/Custom/Resources/`:

### 1. Entity Definition Extensions (`metadata/entityDefs/`)
* **`Preferences.json`**:
  - `smartReminderEnabled` (type: `bool`, default: `true`): Master toggle for smart reminder automation.
  - `smartReminderMode` (type: `enum`, options: `["ai", "rules", "disabled"]`, default: `"ai"`): Controls timing calculation engine.
  - `smartReminderPreparationBuffer` (type: `bool`, default: `true`): Toggles 2-hour preparation buffers for agenda-heavy meetings.
* **`Account.json`**:
  - `aiSummary` (type: `text`): Stores the generated 3-bullet conversation summary.
* **`Meeting.json`, `Call.json`, `Task.json`**:
  - `fields.reminders.view`: Overridden to `"custom:views/meeting/fields/smart-reminders"` to mount the interactive frontend timing component.

### 2. Layout Extensions (`layouts/`)
* **`Preferences/detail.json`**: Appends the "Smart Reminders" settings panel to the user preferences interface.
* **`Account/detail.json`**: Appends the "AI Conversation Summary" side panel to the Account detail view.

### 3. Dashlet Extensions (`metadata/dashlets/`)
* **`ActivitySummary.json`**: Registers the "Activities per Account (Last 30 Days)" dashlet with title configuration and role-based ACL filtering.

---

## 5. Security & Access Control Architecture

1. **Query-Level Strict ACL**:
   All custom reporting tools utilize `SelectBuilderFactory::create()->withStrictAccessControl()`. This ensures that data access policies (ownership, team boundaries, tenant restrictions) are enforced directly within SQL queries rather than in application loops.
2. **Zero-Secret Storage Policy**:
   No API keys, database credentials, or tokens are persisted in source files. Credentials are read exclusively from environment variables (`GROQ_API_KEY`, `ESPOCRM_DATABASE_PASSWORD`).
3. **Data Sanitization & Privacy**:
   - The AI timing engine and conversation summarizer send only relevant activity snippets to external LLM providers.
   - If AI providers are unavailable, the system fails closed (preserves existing summaries or defaults to local deterministic rules), ensuring zero operational downtime.
