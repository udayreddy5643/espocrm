# Week 3 Day 2 — Review and Environment Notes

## Manual walkthrough checklist

- [x] Created a disposable `Training Lead` record in the local EspoCRM instance.
- [x] Converted it to an Account, Contact, and Opportunity.
- [x] Added a follow-up note to the Opportunity Stream.
- [x] Changed the Opportunity stage to `Closed Won` and verified 100% probability.
- [x] Checked the Lead, Contact, and Note REST endpoints with authenticated GET requests.
- [x] Confirmed the Lead response points to the generated Account, Contact, and Opportunity.
- [x] Captured the Lead, Contact, and Note API response screenshots; they are displayed in this task's evidence.
- [ ] Inspect and document existing Workflow rules, BPM flows, and Reports if the Advanced Pack is made available.

## Workflow/BPM/Reports availability

The Administration page in this EspoCRM 10.0.10 community installation links to the Official Extensions catalog. That catalog lists Reports, Workflows, and Business Process Management under the Advanced Pack. Since that extension is absent, there are no accessible existing Workflow rules, BPM flows, or Reports to inspect in this instance. Do not treat the manual Lead conversion as an installed BPM automation rule; it is a standard CRM operation.

| Requested review | Result in this instance |
| --- | --- |
| Three Workflow rules (trigger, conditions, actions) | Not available; Workflows is listed as an Advanced Pack feature. |
| Two BPM process flows | Not available; Business Process Management is listed as an Advanced Pack feature. |
| Two Reports (entity, filters, columns, group by) | Not available; Reports is listed as an Advanced Pack feature. |

## AI review notes

1. Process-design review: keeping Lead, Account, Contact, Opportunity, and Stream Note as separate entities preserves ownership and lets conversion results be inspected through relationships.
2. Edge-case review: this instance has no Advanced Pack rules to inspect, and the test email uses the reserved `.invalid` domain so it cannot reach a real recipient. The follow-up Stream post is a Note record; it is not a scheduled Meeting, Call, or Task.

These notes describe the assistant's review of the implementation and environment. They are not represented as external CIA interactions.
