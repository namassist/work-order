# WOrder: Work Order Flow Specification (v2)

Single source of truth for the business flow. `CLAUDE.md` points here; implementation tasks
reference sections of this file. Items marked **(Provisional)** are developer defaults that still
need confirmation from the business side.

v2 replaces v1 after the business process diagram (Business Process - Work Order Monitoring).
Section 14 lists what changed from v1.

## 1. Purpose and parties

WOrder is an **internal Unggul application** for monitoring work orders received from **IC**,
from input through approval, execution, daily reporting, BAST, closing and payment.

- **All users are Unggul staff.** IC never logs in.
- IC requests reach Unggul's **PIC Work Order** outside the application; the PIC informs the
  **Admin WO**, who enters the WO in the application.
- A WO is **closed** after its BAST is approved. Payment is tracked **separately** after closing (§10).

## 2. Companies and departments

- `companies` stays: IC (client) and Unggul (executor).
- IC departments are used as the **requester department** of a WO (organisation data only; no IC accounts).
- Registration (§3) accepts only executor-company email domains.
- The client isolation code from v1 stays in place as a safeguard (and for a possible future
  read-only IC access), but no client-scoped roles are assigned.

## 3. Roles

| Role | Does |
|---|---|
| Admin WO | Enters, submits, revises, resubmits and closes WOs; cancels before execution |
| Lead Operational | Approves or rejects submitted WOs; cancels during execution. One person |
| PIC Timesheet | Posts the daily reports during execution; submits for document review |
| Rental | Reviews documents; returns for revision or submits the BAST |
| Direktur | Approves BAST (approve only, no rejection) |
| Finance | Manages the payment track after closing (§10) |
| Viewer | Read-only |
| System admin | Users, roles, master data, registrations, BAST templates, activity log |

- "Admin WO" is deliberately not called "Admin", to avoid confusion with the system admin.
- PIC Work Order has **no action** in the application **(Provisional:** optionally recorded on the WO as information, §4).
- Field workers don't log in.
- Registration with admin approval (v1 §3) stays, restricted to Unggul email domains.

## 4. Work order data

Existing fields stay: number, title, description, category, urgency, target date, attachments.

- **Requester department** (an IC department): required.
- **Requester contact name** (the IC person who made the request): required.
- **Entered by**: the Admin WO who created it (`created_by`).
- **PIC Work Order** **(Provisional)**: optional, informational.
- **Target department**: no longer determines who acts. **(Provisional:** keep as an optional
  informational field, or remove.)

## 5. Statuses and transitions

```
Draft → Diajukan → Pelaksanaan → Review Dokumen → Approval BAST → BAST Disetujui → Closed
          ↓   ↑                      ↓
        Ditolak             back to Pelaksanaan (revisi data)

Dibatalkan: see §5.2
Payment track after Closed: §10
```

### 5.1 Transitions

| From → To | By | Requirements |
|---|---|---|
| Draft → Diajukan | Admin WO | Required WO data complete. Number assigned on first submission |
| Diajukan → Pelaksanaan | Lead Operational | – |
| Diajukan → Ditolak | Lead Operational | **Note required** |
| Ditolak → Diajukan | Admin WO | After revision; same number |
| Pelaksanaan → Review Dokumen | PIC Timesheet | At least one daily report (§7) |
| Review Dokumen → Pelaksanaan | Rental | **Note required** (data incomplete) |
| Review Dokumen → Approval BAST | Rental | The application generates the BAST (§8) |
| Approval BAST → BAST Disetujui | Direktur | Approve only; the final BAST PDF is generated with the approval |
| BAST Disetujui → Closed | Admin WO | – |

### 5.2 Cancellation **(Provisional)**

- Admin WO: from Draft, Diajukan or Ditolak, **note required**.
- Lead Operational: from Pelaksanaan or Review Dokumen, **note required**.
- From Approval BAST onwards: no cancellation.

### 5.3 Status properties

| Status | Edit WO | Comments | Files | Overdue basis | Final |
|---|---|---|---|---|---|
| Draft | Admin WO | Yes | Admin WO: dokumen | – | No |
| Diajukan | – | Yes | – | target date | No |
| Ditolak | Admin WO | Yes | Admin WO: dokumen | – | No |
| Pelaksanaan | – | Yes | PIC Timesheet: dokumen; daily reports (§7) | target date; missing daily report | No |
| Review Dokumen | – | Yes | – | target date | No |
| Approval BAST | – | Yes | – | – | No |
| BAST Disetujui | – | Yes | – | – | No |
| Closed | – | Read-only | Finance: payment track (§10) | payment due date (§10) | Yes (for the WO flow) |
| Dibatalkan | – | Read-only | – | – | Yes |

Every status sets every status flag (see `CLAUDE.md`). The number constraint covers every status
after the first submission.

## 6. Visibility

- All internal users with WO access see **every submitted WO**.
- Drafts are visible to Admin WO users (and the system admin), not to other roles.
- Comments, attachments, reports, BAST files, dashboard counts and exports follow the same rule.
- The v1 client isolation stays enforced for any client-company account, even though none are issued.

## 7. Daily reports (timesheet)

The "timesheet" is a **daily progress report**; the detailed timesheet itself is an Excel file or a
link kept outside the application.

- During **Pelaksanaan**, PIC Timesheet posts **one report per WO per working day** (WITA).
- A report contains: the date, a short note, and **at least one** of:
  - Excel file(s) (the existing attachment allowlist, private disk, access follows the WO)
  - link(s) (http/https only)
- A report can be edited on its own day; changes are audit-logged.
- Discussion about the work uses the existing WO comments.
- **Missing report:** a WO in Pelaksanaan with no report for today after the cutoff time is flagged
  "Belum lapor" (list, detail, dashboard).
- **(Provisional):** working days (default Monday–Friday), public holidays, cutoff time
  (default 17:00 WITA), whether late (back-dated) reports are allowed and for how many days, and
  whether links are restricted to company domains (e.g. SharePoint/OneDrive).

## 8. BAST

- When Rental submits the BAST, the application **generates a BAST PDF** from the **active BAST
  template** (§9) and the WO data, and assigns a BAST number (configurable format).
- Direktur approval regenerates the **final** PDF including the approver's name and approval time.
  The final PDF is immutable.
- Each BAST stores **the template version it was generated from**, so later template changes never
  alter issued BASTs.
- **(Provisional):** the official BAST layout, letterhead and signature blocks; ask the business for
  a sample.

## 9. BAST template management

- A system admin page to manage BAST templates:
  - a rich-text editor (reusing the comment editor stack, with an extended allowlist for headings,
    tables, alignment and a letterhead image uploaded to the application)
  - **placeholders** from a fixed allowlist, e.g. `{{nomor_bast}}`, `{{nomor_wo}}`, `{{judul}}`,
    `{{departemen_pemohon}}`, `{{kontak_pemohon}}`, `{{tanggal_bast}}`, `{{nama_direktur}}`,
    `{{tanggal_persetujuan}}`
  - preview with a real WO
  - **versioning**: publishing creates a new version; exactly one version is active
- **Security:** templates are HTML with simple placeholder substitution and **escaped values**.
  Templates are never compiled or evaluated as Blade/PHP (no server-side template injection).
  Template HTML is sanitized on save like comments.

## 10. Payment track (after Closed)

Finance manages a separate payment status on closed WOs:

```
Belum ditagih → Ditagih → Lunas
```

- **Ditagih:** invoice number (unique, case-insensitive), invoice date, amount (optional),
  payment due date (optional), and **at least one invoice file**.
- **Lunas:** payment date (not before the invoice date, not in the future, WITA) and optional proof of payment.
- The invoice can be corrected while Ditagih (audit-logged), as built in v1 step 3.
- **Overdue:** Ditagih past its due date.
- **Segregation of duties** (issuer ≠ confirmer) becomes a **setting, default off**, because the
  process has a single Finance lane.

## 11. Deadlines and overdue

- **Target date:** late while Diajukan, Pelaksanaan or Review Dokumen and past the target date.
- **Missing daily report:** §7.
- **Payment due date:** late while Ditagih and past the due date.
- A WO without the relevant date is never late on that basis.

## 12. Notifications (after the flow is rebuilt)

Minimum in-app events: submitted (Lead Operational), rejected (Admin WO), approved (PIC Timesheet),
missing daily report (PIC Timesheet), submitted for review (Rental), returned for revision
(PIC Timesheet), BAST submitted (Direktur), BAST approved (Admin WO), closed (Finance),
payment overdue (Finance). The actor is never notified of their own action.

## 13. Open points

- Official BAST format and a sample document.
- Working days, holidays, report cutoff time, back-dated reports, link domain restriction (§7).
- Whether PIC Work Order is recorded on the WO; keep or remove the target department (§4).
- Cancellation rules (§5.2).
- Mail (Microsoft 365 SMTP) and Microsoft sign-in (SSO).
- Instalments (termin) for payment.

## 14. What changed from v1

- No IC users: IC is requester organisation data only; registration is Unggul-only.
- Approval by **Lead Operational** instead of the target department accepting the WO.
- **Pelaksanaan** replaces Dikerjakan, with **daily reports**.
- New **Review Dokumen**, **Approval BAST** and **BAST Disetujui** stages, with a generated BAST and
  editable BAST templates.
- **Closed** after BAST approval; **payment is a separate track** (replaces the Penagihan and
  Selesai statuses). The invoice table and correction flow are reused.
- Visibility: all internal users see all submitted WOs; drafts only for Admin WO.
- Segregation of duties for payment becomes an optional setting.

## 15. Implementation order

1. This document (reviewed and confirmed)
2. Roles, visibility, simplified WO form (IC department + contact name), Unggul-only registration
3. Status flow v2 (§5), cancellation, and the payment track (§10) reusing the invoice data
4. Daily reports (§7)
5. BAST templates, generation and approval (§8, §9)
6. **Demo and validation with the business users**, especially daily reports and BAST
7. Dashboard, filters and export for v2
8. Notifications (§12)

Each step: one branch and one PR, planned with `/ecc:orch-*` and stopped at Gate 1.
Migration note: v1 statuses Dikerjakan, Penagihan and Selesai map to the v2 flow in step 3; there is
no production data, so the demo data may be regenerated.
