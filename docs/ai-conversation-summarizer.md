# AI Conversation Summarizer

The Account summary is refreshed asynchronously after a Call, Meeting, Task, or Email linked to an Account is saved. It considers the five most recent linked interactions and stores the result in `Account.aiSummary`.

## Provider configuration

Set these environment variables on the EspoCRM PHP and job-worker services. Keep keys in a local `.env` file or the deployment secret store; never commit key values.

- `GROQ_API_KEY` (primary)
- `GROQ_MODEL` (optional; defaults to `openai/gpt-oss-120b`)
- `OPENROUTER_API_KEY` (fallback)
- `OPENROUTER_MODEL` (required when the fallback key is set)

Groq is attempted first. If it is unavailable, errors, or returns no summary, OpenRouter is tried. If both fail or are unconfigured, the existing Account summary is preserved and a warning without credentials or interaction text is written to the application log.

## Manual verification

1. Configure a valid Groq key and model. Optionally configure a separate OpenRouter key and model.
2. Create or select an Account and log at least five Calls, Meetings, Tasks, or Emails against it, each with a short description.
3. Wait for the job worker, then reopen the Account. The **AI Conversation Summary** side panel should contain three concise bullets based on the five latest linked interactions.
4. To check fallback, temporarily use an invalid Groq key while OpenRouter is configured, save another linked activity, and confirm the summary refreshes. Restore the valid key afterward.
5. To check failure handling, configure neither provider and save an activity. Activity saving should succeed; the previous summary should remain and the app log should contain a warning without secrets or note contents.

Only the five latest interaction snippets are sent to the selected external provider. Review your organization's data-sharing requirements before enabling this feature with real customer data.
