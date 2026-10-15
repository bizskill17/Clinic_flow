# Clinic Management System (Spa · Aesthetics · Pharmacy)

Exported on 15 Oct 2026 from the claude.ai version of the app.

## What's in this folder

```
clinic-management-system/
├── index.html          The whole app: HTML, CSS and JavaScript in one file
├── data/
│   ├── backup.json     Every record from the app, grouped by collection
│   └── files/          Uploaded session photos and consent PDFs (named by their id)
└── README.md
```

`data/` holds real client and medical data. Keep it private, and don't commit it to a public Git repository. Add `data/` to `.gitignore`.

## Open it in VS Code

1. Open the folder in VS Code.
2. Install the **Live Server** extension (Ritwick Dey).
3. Right-click `index.html` and choose **Open with Live Server**.

The app opens with the banner *"Local copy: data loaded from data/backup.json. Changes are not saved."* You can click through every screen and test changes, but nothing is saved, because there's no database yet (see below). Opening the file directly (`file://`) also works, but then the backup data doesn't load.

PDFs and CSV exports download as normal browser downloads. The PDF tool (jsPDF) and the fonts load from the internet, so you need to be online.

## How the code is organised (index.html)

| Part | What it is |
|---|---|
| `<style>` at the top | All styling. Colour tokens are in `:root`, with dark mode under `prefers-color-scheme`. |
| First `<script>` (`window.PDFKIT`) | PDF layouts: `invoice`, `receipt`, `prescription`, `quotation`, `aeInvoice`, `consent`. Uses jsPDF and an embedded DejaVu font for the ₹ sign. |
| Second `<script>` | The app. Search for these markers: |
| `var S=`, `var COL=` | In-memory data (`S`) and the database collection names (`COL`) |
| `function initStore`, `put`, `remove` | **The storage layer.** This is the only place that talks to the database. |
| `getAssets()` | Photo and consent file uploads |
| `saveFile()` | Downloads |
| `SECTIONS[...]` | One entry per screen (`mount` draws it, `update` refreshes it with data) |
| `allBills`, `allocate`, `clientAccount` | One account per client across all departments (bills, receipts, advances) |
| `qtLineStats`, `ssRows` | Quotation sessions and the Sessions Tracker |
| `deptStock`, `deptMoves`, `consBlock` | Spa and Aesthetics stock, consumables and usage |
| `consentNeeded`, `openConsent` | Consent forms (signature pad or uploaded PDF) |

## Storage today and how to replace it

On claude.ai the app uses the page's built-in services through `window.claude`:
- `claude.use('db')`: a document database. Every record is a document `{id, ...fields}` inside a collection listed in `COL`, and changes stream in live through `onSnapshot`.
- `claude.use('assets')`: file storage for photos and consent PDFs. Records keep only the file `id`.
- `claude.use('downloads')`: PDF and CSV downloads. There's already a browser-download fallback.
- `claude.use('user')`: read-only or contributor detection.

Outside claude.ai none of these exist, so the app falls back to local, unsaved mode. To run it on your own server (for example Hostinger with PHP + MySQL), only these pieces need replacing:

1. **`initStore()`**: load every collection from your API into `S[kind]`, set `loaded[kind]=true`, call `scheduleRefresh()`, and poll for changes every few seconds.
2. **`put(kind, obj)`**: send the record to your API (`COL[kind]` is the collection name, `obj.id` the key) and update `S[kind]`.
3. **`remove(kind, id)`**: delete through your API.
4. **`getAssets()`**: return an object with `upload(blob,{type})` resolving `{id,url}` and `delete(id)`, backed by your server's upload folder. Then point `blobUrl()` at your file URL.
5. **Login + roles**: add a login screen before `initStore()` and check permissions on the server.

A simple MySQL design that fits the app without changing any screens is a single table:

```sql
CREATE TABLE docs (
  collection VARCHAR(64)  NOT NULL,
  id         VARCHAR(100) NOT NULL,
  data       LONGTEXT     NOT NULL,      -- the record as JSON
  updated_at DATETIME(3)  NOT NULL,
  PRIMARY KEY (collection, id),
  KEY (updated_at)
);
```

`data/backup.json` imports straight into it: one row per record, with `collection` set to the key in the file.

## Collections (data/backup.json)

Spa: `spa_clients`, `spa_services`, `spa_invoices`, `spa_receipts` (receipts for all departments), `spa_categories`, `spa_cities`, `spa_states`, `spa_users`, `spa_business` (profile + logo)
Pharmacy: `ph_items`, `ph_item_categories`, `ph_suppliers`, `ph_moves` (purchases, returns, sales, expiry, damage, Spa/Aesthetics issues), `ph_receipts`
Aesthetics: `ae_proc_categories`, `ae_procedures`, `ae_appointments`, `ae_consultations`, `ae_quotations`, `ae_sessions`, `ae_invoices`, `ae_consents`
Department stock: `dept_openings`, `dept_usage`

## Notes

- Dates are stored as `YYYY-MM-DD`; times are IST.
- Document numbers (INV-, RCT-, SES- …) come from each record's `seq`, and the lowest free number is reused.
- Consumables are internal only and never printed on client PDFs.
# Clinic_flow
