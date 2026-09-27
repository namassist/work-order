# WOrder: Work Order Flow Specification

Single source of truth for the business flow. `CLAUDE.md` points here; implementation
tasks reference sections of this file. Items marked **(Proposed)** are defaults chosen by the
developer and still need confirmation from the business side.

## 1. Purpose and parties

WOrder tracks work orders from **IC** (client) to **Unggul** (executor), from request through
execution and invoicing until payment is received.

- Direction is always **IC → Unggul**. Unggul never sends work orders to IC.
- IC departments request work. Unggul departments execute it and invoice IC.
- A WO is **closed** only when the work is done **and** payment has been received.

## 2. Companies and departments

- `companies`: name, code, `is_client` flag, allowed email domains. Seeded: IC (client), Unggul (executor).
  A table rather than a fixed enum, so another client company can be added as data later.
- Every department belongs to exactly one company.
- A WO's **requester department** must belong to a client company (IC).
- A WO's **target department** must belong to the executor company (Unggul).

## 3. Users, roles and registration

### Roles

| Role                                            | Company | Can                                                                                                    |
| ----------------------------------------------- | ------- | ------------------------------------------------------------------------------------------------------ |
| `pemohon`                                       | IC      | Create, edit, submit, revise, resubmit and cancel WOs for their own department                         |
| `pelaksana` **(Proposed, replaces `approver`)** | Unggul  | Act on WOs addressed to their department: accept, reject, start, move to invoicing                     |
| `keuangan`                                      | Unggul  | See all submitted WOs; confirm payment received (Penagihan → Selesai)                                  |
| `koordinator` **(Proposed)**                    | Unggul  | Enter WOs on behalf of IC (`work-orders.create-on-behalf`) and act as the requester side for those WOs |
| `viewer`                                        | Either  | Read-only within their visibility                                                                      |
| `admin`                                         | Unggul  | Everything, including user and master data management and registration approval                        |

A user can hold multiple roles, but only roles that fit their company.

### Registration (self-service, admin-approved)

1. Anyone can register with: name, email, password, company, department (filtered by company).
2. The email domain must match one of the selected company's allowed domains
   (both companies use their own domains on Microsoft 365 / Outlook). The company is pre-selected
   from the email domain.
3. New accounts are **pending**: after login they only see "Akun Anda sedang ditinjau admin".
4. Admin reviews pending registrations (sidebar badge with count):
    - **Approve:** assign role(s); may correct company and department.
    - **Reject:** with a reason.
5. No role is ever granted automatically.
6. No forced password change (the user chose the password).
7. Named rate limiter on registration. Registration, approval and rejection are audit-logged.
8. Admin-created accounts (existing flow with `DEFAULT_USER_PASSWORD` and forced change) remain.

## 4. Work order data

Existing fields stay: number, title, description, category, urgency, target date, attachments.
New fields:

- **Requester department** (IC): always required.
- **Requester account**: set when the requester has an account.
- **Requester contact name**: set when entered on behalf of someone without an account
  (e.g. "Pak Andi, Maintenance IC"). Exactly one of account or contact name is set.
- **Entered by**: the user who created the WO (existing `created_by`).
- **Target department** (Unggul): required before submission.

Creation paths:

- **IC user:** creates for their own department; requester = themselves.
- **Unggul koordinator:** picks the IC department, then an IC account or a contact name.

Categories do not change the flow; they are for classification only.

## 5. Statuses and transitions

```
Draft → Diajukan → Dikerjakan → Penagihan → Selesai
          ↓   ↑
        Ditolak

Dibatalkan: from Draft, Diajukan or Ditolak
```

"Requester side" = users of the requester department with `pemohon`, or the koordinator who
entered the WO.

| From → To                               | By                                 | Requirements                                                                                                |
| --------------------------------------- | ---------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| Draft → Diajukan                        | Requester side                     | Target department set. Number assigned on the first submission only                                         |
| Diajukan → Dikerjakan                   | Pelaksana of the target department | Optional note                                                                                               |
| Diajukan → Ditolak                      | Pelaksana of the target department | **Note required**                                                                                           |
| Ditolak → Diajukan                      | Requester side                     | After revision; keeps the same number                                                                       |
| Dikerjakan → Penagihan                  | Pelaksana of the target department | Invoice number, invoice date and **at least one invoice file** required; amount, due date and BAST optional |
| Penagihan → Selesai                     | Keuangan (Unggul)                  | Payment date required; proof of payment optional                                                            |
| Draft / Diajukan / Ditolak → Dibatalkan | Requester side                     | **Note required**                                                                                           |

Every transition writes a status history row and an audit entry.

### Status properties

| Status     | Requester can edit                    | Comments  | Attachments              | Overdue basis    | Final |
| ---------- | ------------------------------------- | --------- | ------------------------ | ---------------- | ----- |
| Draft      | Yes                                   | Yes       | Requester: dokumen       | none             | No    |
| Diajukan   | No                                    | Yes       | none                     | target date      | No    |
| Ditolak    | Yes, including the target department¹ | Yes       | Requester: dokumen       | none             | No    |
| Dikerjakan | No                                    | Yes       | Pelaksana: BAST, dokumen | target date      | No    |
| Penagihan  | No                                    | Yes       | Keuangan: bukti bayar    | payment due date | No    |
| Selesai    | No                                    | Read-only | none                     | none             | Yes   |
| Dibatalkan | No                                    | Read-only | none                     | none             | Yes   |

¹ A common rejection reason is "wrong department", so in Ditolak the requester side may pick
another target department before resubmitting (still an executor department; it cannot be cleared).

These map to the existing status flags (`isEditable`, `acceptsComments`,
`countsAsOverdueWhenLate`, `requiresTargetDepartment`) plus new ones as needed. Every new status
must set all flags. `requiresTargetDepartment` is true for every status after the first submission
(Diajukan, Ditolak, Dikerjakan, Penagihan, Selesai), so a submitted WO never loses its target;
it is false for Draft and Dibatalkan (a draft can be cancelled before a target is chosen).

## 6. Visibility and isolation

- **IC users:** WOs of their own department (including ones entered by a koordinator).
  Never another IC department's WOs.
- **Pelaksana:** WOs addressed to their department, from Diajukan onward. **Drafts are never
  visible to Unggul**, except to the koordinator who entered them.
- **Koordinator:** WOs they entered, plus normal visibility of their own department.
- **Keuangan and admin:** all WOs except other people's drafts (`view-all`).
- Comments, attachments, timeline, dashboard counts and exports all follow the same WO visibility.
- **IC users can never open** users, roles, departments, categories, companies, registrations,
  or the activity log, and see no Unggul-internal navigation.
- Other companies' WOs return 404 (existing `denyAsNotFound` convention).

## 7. Deadlines and overdue

Deadlines are optional.

- **Target date** (existing): target for completing the work. Overdue while Diajukan or
  Dikerjakan and past the target date (WITA).
- **Payment due date** (new, optional, set when moving to Penagihan): overdue while Penagihan
  and past the due date (WITA).
- A WO without the relevant date is never overdue.

## 8. Invoicing

- Invoice data: number and date (required), amount and due date (optional).
- Attachment collections: `invoice` (required, at least one), `bast` (optional),
  `bukti_bayar` (optional, added by keuangan).
- Keuangan confirms payment received with a payment date; the WO becomes Selesai.

## 9. Comments

- Progress is reported through comments.
- Rich text (WYSIWYG) with images and documents: Tiptap on the frontend, **HTML sanitized on the
  server**, embedded files stored through the existing attachments module (private disk, access
  follows the WO).
- Existing rules stay: edit and delete by the author within the edit window; read-only on final
  statuses; `work-orders.comment` permission to write.

## 10. Notifications (Proposed)

In-app notifications at minimum:

- New WO submitted → pelaksana of the target department
- Rejected (with note) → requester side
- Accepted / started → requester side
- Moved to Penagihan → keuangan and requester side
- Selesai → requester side
- New comment → the other side of the WO

Email notifications depend on the mail setup (see open points).

## 11. Open points

- **Mail:** both companies use Microsoft 365. Is SMTP through Microsoft 365 available to the app?
  That enables email verification, forgot password, and email notifications.
- **Sign in with Microsoft (SSO):** possible later through Microsoft Entra ID; could replace
  passwords and simplify registration.
- Cancelling after Dikerjakan: allowed? By whom?
- Internal notes visible only to Unggul.
- Whether pelaksana may change urgency or target date.
- Which roles may export.
- Whether the WO number should include the target department.

## 12. Implementation order

0. This document (reviewed and confirmed)
1. Cross-company foundation: companies, target department, requester vs entered-by,
   on-behalf creation, visibility and IC isolation, roles, demo seeder
   1b. Registration with admin approval and email domain rules
2. Full status flow (Section 5)
3. Invoicing (Section 8)
4. Deadlines, dashboard, filters and export for the new statuses and fields
5. Rich-text comments with images and documents
6. Notifications
7. Validation session with the business users

Each step: one branch and one PR, planned with `/ecc:orch-*` and stopped at Gate 1.
Remove the matching **PROVISIONAL** notes from `CLAUDE.md` as each step lands.
