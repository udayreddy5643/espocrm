# Week 4, Day 2: Smart Reminders — Frontend UI + User Preference Settings

## Overview & Scope

Building on the Week 4 Day 1 backend timing engine and hook architecture, Week 4 Day 2 delivers the **User Preference Settings** and **Interactive Frontend UI**:
1. **User Preference Settings (`Preferences`)**: Adds granular controls to user preferences (`smartReminderEnabled`, `smartReminderMode`, `smartReminderPreparationBuffer`), allowing users to toggle smart automation, select operational mode (`ai`, `rules`, or `disabled`), and configure preparation buffer suggestions.
2. **Preference-Aware Hook Integration**: Updated `SmartReminderHook` to dynamically resolve the assigned user's preferences via the entity manager, respecting disabled status and preference-defined buffer settings.
3. **Frontend UI Extension (`smart-reminders.js`)**: Extends EspoCRM's standard `crm:views/meeting/fields/reminders` view (`client/custom/modules/custom/src/views/meeting/fields/smart-reminders.js`), adding an interactive **"Smart Timing"** button (`suggestSmartReminders`), dynamic loading spinner, live recommendation badge (AI vs Rules), human-readable reason banner, and automated form population.
4. **Metadata Routing**: Configured `Meeting`, `Call`, and `Task` entity definitions to seamlessly route their `reminders` field to `custom:views/meeting/fields/smart-reminders`.

---

## Architectural Implementation

### 1. User Preference Settings

#### Entity Definitions (`custom/Espo/Custom/Resources/metadata/entityDefs/Preferences.json`)
- `smartReminderEnabled` (type `bool`, default `true`): Master switch for automated reminder calculation.
- `smartReminderMode` (type `enum`, options `["ai", "rules", "disabled"]`, default `"ai"`): Operational mode selection.
- `smartReminderPreparationBuffer` (type `bool`, default `true`): Toggles 2-hour advance preparation reminders when agenda keywords (deck, demo, review, prep, contract) are present.

#### Detail Layout (`custom/Espo/Custom/Resources/layouts/Preferences/detail.json`)
Appends a dedicated "Smart Reminders" section to the User Preferences screen with responsive panel formatting.

#### Internationalization (`custom/Espo/Custom/Resources/i18n/en_US/Preferences.json`)
Provides localized labels, option titles, and descriptive tooltips for all new preference attributes.

### 2. Preference-Aware Hook Logic (`SmartReminderHook.php`)

During `beforeSave` on `Meeting`, `Call`, and `Task`:
1. Resolves the assigned user (`assignedUserId` or current user fallback).
2. Loads the user's `Preferences` record via `EntityManager`.
3. Verifies:
   - If `smartReminderEnabled === false` or `smartReminderMode === 'disabled'`, smart calculation is skipped.
   - If `smartReminderPreparationBuffer === false`, disables agenda buffer heuristics in `RuleEngine`.
   - Propagates mode preference (`ai` vs `rules`) to `SmartReminderService`.

### 3. Frontend View Extension (`smart-reminders.js`)

- **View Path**: `client/custom/modules/custom/src/views/meeting/fields/smart-reminders.js`
- **Inherits From**: `crm:views/meeting/fields/reminders` (`Espo.Ui.Views.MeetingRemindersField`)
- **Key Methods**:
  - `afterRender()`: In edit/detail mode, renders a styled "Smart Timing" button (`data-action="suggestSmartReminders"`).
  - `suggestSmartReminders(e)`:
    - Validates that `dateStart` is present on the model (prompts notification if missing).
    - Transitions button to loading state (`disabled`, spinning icon, text `"Analyzing..."`).
    - Issues `POST` request via `Espo.Ajax.postRequest('SmartReminder/action/suggestTiming', payload)`.
    - Converts API response reminders into the model's reminder collection format.
    - Updates model attributes via `this.model.set('reminders', formattedReminders)` and calls `this.trigger('change')`.
    - Renders an inline badge displaying recommendation source (`AI Suggested` / `Rule Heuristic`) and reasoning.
    - Handles errors gracefully with user-friendly alerts.

---

## CIA (AI Collaboration) Interaction Log

### Session 1: Mentor Mode Architecture Review
- **Prompt / Topic**: Reviewing EspoCRM client architecture for extending Backbone.js field views without breaking native row-addition logic.
- **Feedback & Decision**:
  - Rather than rebuilding the reminder list view from scratch, inherit from `crm:views/meeting/fields/reminders`.
  - In EspoCRM AMD module loader, views placed in `client/custom/modules/custom/src/views/...` map cleanly to `custom:views/...`.
  - Overriding `reminders.view` in metadata (`Meeting.json`, `Call.json`, `Task.json`) injects the custom view transparently across all activities while maintaining full backward compatibility.

### Session 2: Code Mode Edge Cases & State Management
- **Prompt / Topic**: Analyzing frontend race conditions and edge cases in `smart-reminders.js`.
- **Edge Cases Identified & Handled**:
  - *Missing Start Date*: User clicks "Smart Timing" before filling `dateStart`. Handled by displaying an immediate warning banner without making redundant API calls.
  - *Double-click / Rapid API calls*: Button is disabled and marked busy immediately upon invocation.
  - *Zero Reminders / Edge Time*: If the backend returns empty or immediate reminders, the UI reflects the result cleanly without breaking collection binding.
  - *Re-rendering*: The source badge and feedback banner clean up previous instances before rendering fresh recommendations.

---

## Verification & Test Results

### 1. Automated PHPUnit Test Suite
The test suite was expanded to 30 unit tests covering preference integration:

```text
PHPUnit 11.5.57 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.5.12
Configuration: /app/phpunit.xml

..............................                                    30 / 30 (100%)

Time: 00:00.220, Memory: 29.52 MB

OK (30 tests, 111 assertions)
```

- `PreferencesIntegrationTest` (4 new tests):
  - `testHookSkipsWhenSmartRemindersDisabledInPreferences`: Verifies hook skips reminder population when `smartReminderEnabled` is false.
  - `testHookSkipsWhenSmartReminderModeIsDisabled`: Verifies hook skips when `smartReminderMode` is set to `"disabled"`.
  - `testHookRespectsPreparationBufferPreference`: Verifies 2-hour preparation buffer is omitted when user disables `smartReminderPreparationBuffer`.
  - `testHookRespectsExplicitRemindersRegardlessOfPreferences`: Verifies user-specified reminders are never overwritten.

### 2. Live Metadata Resolution
Container metadata was rebuilt and verified via `command.php rebuild` and live container inspection:
- `Meeting.fields.reminders.view` -> `custom:views/meeting/fields/smart-reminders`
- `Call.fields.reminders.view` -> `custom:views/meeting/fields/smart-reminders`
- `Task.fields.reminders.view` -> `custom:views/meeting/fields/smart-reminders`
- `Preferences.fields.smartReminderEnabled` -> `{"type":"bool","default":true}`
- `Preferences.fields.smartReminderMode` -> `{"type":"enum","options":["ai","rules","disabled"],"default":"ai"}`
- `Preferences.fields.smartReminderPreparationBuffer` -> `{"type":"bool","default":true}`

---

## Self-Review Checklist

- [x] **Git conventions**: Feature branch `feat/cynaris-w4d2-uday-b` created from `feat/cynaris-w4d1-uday-b`.
- [x] **Commit hygiene**: Minimum 2 distinct commits (`feat(preferences)` and `feat(ui)`), no mega-commit.
- [x] **Zero Secrets**: No API keys or credentials committed to source code.
- [x] **Extensibility**: Used EspoCRM extension paths (`custom/Espo/Custom/` and `client/custom/modules/custom/`) without touching core files.
- [x] **Verification**: All 30 unit tests pass, and container metadata successfully tested.

---

## Viva Questions & Answers

### Q1: How does EspoCRM's client view inheritance work, and how is custom view routing configured?
**Answer**:
EspoCRM uses AMD (`define([...], function(...) { ... })`) with Backbone.js views. To extend a core view:
1. Define a new module under `client/custom/modules/custom/src/views/...` that imports the base view (`crm:views/meeting/fields/reminders`).
2. Extend the prototype using `BaseView.extend({ ... })`, calling `BaseView.prototype.methodName.call(this)` when overriding lifecycle hooks like `afterRender` or `events`.
3. In metadata (`custom/Espo/Custom/Resources/metadata/entityDefs/{Scope}.json`), configure `"fields": { "reminders": { "view": "custom:views/meeting/fields/smart-reminders" } }`. EspoCRM's ViewFactory inspects field metadata and instantiates the custom view class automatically.

### Q2: How do User Preferences interact with backend hooks and per-user customization in EspoCRM?
**Answer**:
In EspoCRM, user settings are stored in the `Preferences` entity keyed by `userId`. Custom attributes defined in `metadata/entityDefs/Preferences.json` are persisted in the database and loaded into memory per user session. During entity lifecycle events (`beforeSave`), backend hooks query `$entityManager->getEntity('Preferences', $assignedUserId)` to retrieve the specific user's preferences (e.g., whether smart reminders or preparation buffers are enabled) and adapt processing behavior dynamically before records are saved.

### Q3: How does the frontend handle loading states, API latency, and fallback states when requesting AI suggestions asynchronously?
**Answer**:
In `smart-reminders.js`:
- **Loading State**: When the user clicks the "Smart Timing" button, the button is immediately disabled, assigned a `.busy` class, and its icon is swapped to an animated spinner (`fas fa-spinner fa-spin`) to prevent duplicate submissions.
- **Asynchronous Execution**: The request is dispatched via `Espo.Ajax.postRequest` which returns a Promise.
- **Success & Fallback Display**: When the response resolves, the button returns to its active state, and a dynamic status badge displays whether the suggestion originated from `"ai"` (`AI Suggested` badge) or deterministic fallback rules (`Rule Heuristic` badge) along with the human-readable explanation.
- **Error Handling**: If the network or API call fails, the `.catch()` block catches the error, re-enables the button, and displays an informative notification without corrupting existing reminder values.
