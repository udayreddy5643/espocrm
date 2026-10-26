# Week 4, Day 1: Smart Reminders — Automation Rules + AI-Suggested Timing

## Overview & Scope

In standard EspoCRM, reminders on activities (`Meeting`, `Call`, `Task`) are either manually entered by users or populated with static user-preference offsets (e.g. fixed 15-minute popup). Standard reminders lack context-awareness: they do not factor in lead time, event urgency/priority, preparation requirements, or multi-stage advance alerts (e.g., email 1 day prior + popup 30 minutes prior).

Week 4 Day 1 implements **Smart Reminders**:
1. **Rule Engine (`RuleEngine.php`)**: A deterministic heuristics engine evaluating lead time, activity priority, and agenda/preparation cues to produce tailored multi-stage reminders (Popup/Email).
2. **AI Timing Suggester (`AiSuggester.php`)**: An LLM-backed timing adviser that evaluates meeting agendas and titles to suggest context-aware notification lead times, adhering to the organization's Groq / OpenRouter provider pattern with zero-leak error handling.
3. **Smart Reminder Service (`Service.php`)**: Coordinates AI recommendations with a seamless, zero-downtime fallback to deterministic rules when external AI providers are unconfigured, unreachable, or rate-limited.
4. **API Endpoint (`SmartReminder.php` Controller)**: Exposes `POST /api/v1/SmartReminder/action/suggestTiming` for client-side timing suggestions.
5. **Automation Hook (`SmartReminderHook.php`)**: Evaluates during `beforeSave` on `Meeting`, `Call`, and `Task` to auto-populate reminders when none are explicitly provided, enabling EspoCRM's native `FieldProcessing\Reminder\Saver` to generate native `Reminder` database records.

---

## Architectural Findings from EspoCRM

### 1. Existing Reminder Mechanism
- **Entity**: `Espo\Modules\Crm\Entities\Reminder` persists in the `reminder` table (`entityId`, `entityType`, `type`, `userId`, `remindAt`, `startAt`, `seconds`, `isSubmitted`).
- **Saver Field Processor**: `Espo\Core\FieldProcessing\Reminder\Saver` runs during `afterSave` on event entities (`Meeting`, `Call`, `Task`). It reads `$entity->get('reminders')` (an array of `{"type": "Popup"|"Email", "seconds": int}`) and inserts rows into `reminder`.
- **Scheduled Workers**:
  - `Espo\Modules\Crm\Jobs\SubmitPopupReminders`: Periodic job querying `remindAt <= now` for popup alerts.
  - `Espo\Modules\Crm\Jobs\SendEmailReminders`: Periodic job sending email notifications via `EmailReminder->send()`.

### 2. Integration Strategy
Rather than bypassing EspoCRM's native reminder table or creating parallel tables, the Smart Reminder engine calculates the optimal reminders array and injects it into `$entity->set('reminders', ...)` in `beforeSave`. This leverages EspoCRM's battle-tested reminder dispatcher, email templates, and popup notification popups without modifying core files.

---

## Automation Rule Matrix

| Lead Time | Priority | Context / Keyword Cues | Calculated Reminders | Channel(s) | Reasoning |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **$\le$ 10 mins** | Any | Any | `0s` (at event time) | Popup | Immediate reminder for immediate start |
| **10 – 30 mins** | Any | Any | `300s` (5m before) | Popup | Short-notice alert |
| **30 – 60 mins** | Any | Any | `600s` (10m before) | Popup | Short-notice alert |
| **1h – 24h** | Normal / Low | No prep cues | `1800s` (30m before) | Popup | Same-day standard reminder |
| **1h – 24h** | High / Urgent | Any | `7200s` (2h before) + `3600s` (1h before) + `1800s` (30m before) | Email + Popup | Escalated same-day multi-channel coverage |
| **1h – 24h** | Any | Prep cues (deck, demo, review, prep, contract) | `7200s` (2h before) + `1800s` (30m before) | Popup | Preparation buffer for material review |
| **> 24 hours** | Normal / Low | Any | `86400s` (1d before) + `1800s` (30m before) | Email + Popup | Advance multi-stage alert |
| **> 48 hours** | High / Urgent | Any | `172800s` (2d before) + `86400s` (1d before) + `1800s` (30m before) | Email + Popup | High-priority multi-day advance warning |

---

## AI Provider Configuration

Following the repository's established AI provider architecture (`docs/ai-conversation-summarizer.md`), keys are read directly from environment variables. Never commit API keys to source files or version control.

### Supported Environment Variables
- `GROQ_API_KEY`: Primary AI provider API key.
- `GROQ_MODEL`: Model name (optional; defaults to `openai/gpt-oss-120b`).
- `OPENROUTER_API_KEY`: Fallback provider API key.
- `OPENROUTER_MODEL`: Model name (required when OpenRouter key is set).

### Fallback & Privacy Guarantees
1. Groq is attempted first. If unconfigured or unavailable, OpenRouter is attempted.
2. If both fail or neither is configured, the system automatically falls back to deterministic rule-based suggestions. No user request fails or errors out.
3. Errors are logged to the CRM application log without exposing API keys, prompt payloads, or note contents.

---

## API Specification

### Endpoint: Suggest Timing
- **URL**: `POST /api/v1/SmartReminder/action/suggestTiming`
- **Headers**:
  - `Content-Type: application/json`
  - `Espo-Authorization` or CRM session cookie

#### Request Body
```json
{
  "entityType": "Meeting",
  "name": "Enterprise Architecture Review & Demo",
  "description": "Prepare architecture diagram and walk through slide deck.",
  "dateStart": "2026-10-30 14:00:00",
  "priority": "High",
  "useAi": true
}
```

#### Response (200 OK)
```json
{
  "reminders": [
    {
      "type": "Email",
      "seconds": 172800
    },
    {
      "type": "Email",
      "seconds": 86400
    },
    {
      "type": "Popup",
      "seconds": 7200
    }
  ],
  "source": "rules",
  "reason": "Advance event (1d email + 30m popup); High-priority multi-day alert (2d email); Preparation/agenda buffer detected (2h prep popup)",
  "leadTimeSeconds": 259200
}
```

---

## Verification & Test Results

### 1. Automated PHPUnit Test Suite
A comprehensive test suite of 26 unit tests was authored and executed using PHPUnit 11 inside the PHP 8.5 test environment:

```text
PHPUnit 11.5.57 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.5.12
Configuration: /app/phpunit.xml

..........................                                        26 / 26 (100%)

Time: 00:00.229, Memory: 29.52 MB

OK (26 tests, 92 assertions)
```

- `RuleEngineTest`: 10 tests verifying immediate notice (<10m), short notice (10-30m, 30-60m), same-day notice, high-priority escalation, multi-day advance notice, preparation keyword matching, and past-event boundaries.
- `AiSuggesterTest`: 7 tests verifying unconfigured API key handling, valid JSON extraction, Markdown code fence stripping, channel filtering, lead-time bounds clamping, and malformed JSON resilience.
- `ServiceTest`: 4 tests verifying AI success routing, rule fallback routing, explicit `useAi=false` bypass, and past event skipping.
- `SmartReminderHookTest`: 5 tests verifying unsupported scope filtering, empty start date handling, preservation of explicit user reminders, automated calculation for new records, and explicit override flags.

### 2. Live Container Verification
All classes were deployed into the running EspoCRM container (`espocrm-php`), confirmed registered via `command.php rebuild`, and verified using live container execution. Autoloading, dependency injection, and evaluation executed successfully without errors.

---

## Manual Verification Checklist

1. **Verify Live Rebuild**:
   Run `docker exec espocrm-php php /var/www/html/command.php rebuild`. Verify output displays `Rebuild has been done.`
2. **Test API via cURL / Postman**:
   Send a `POST` request to `http://localhost:8080/api/v1/SmartReminder/action/suggestTiming` with CRM credentials and a future `dateStart`. Verify that the response returns the computed `reminders` array, `source`, and `reason`.
3. **Verify Preparation Keyword Rule**:
   Create a meeting with title `"Demo and Presentation Review"` scheduled 5 hours in the future. Confirm that the reminder list includes an advance 2-hour popup alert.
4. **Verify Entity Save Hook**:
   Create a new Meeting via CRM UI or API without specifying `reminders`. Reopen the Meeting record or query the `reminder` table (`SELECT * FROM reminder WHERE entity_id = :id;`). Confirm the native `reminder` records were automatically created with matching offsets.
5. **Verify AI Fallback**:
   Ensure `GROQ_API_KEY` is not set. Send a request to `suggestTiming`. Verify that `source` returns `"rules"` with zero errors logged.
