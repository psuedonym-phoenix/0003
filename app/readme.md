# EEMS Purchase Order System

Lightweight PHP/MySQL backend and web UI for managing purchase orders. Excel `.xlsm` workbooks remain the data entry tool; data is posted as JSON and persisted in MySQL (`filiades_eems`). Web pages and new non-Excel-related files live in `/app/`.

## Scope & Rules
- Existing files in the repo root stay in place and are not to be moved/renamed.
- New files unrelated to Excel tooling belong in `/app/`.
- Preserve the database schema and JSON contracts; any DB change must be backward-compatible.
- Use procedural PHP with PDO prepared statements; avoid heavy frameworks or Composer.
- Keep Excel/VBA interoperability intact (field meanings, payloads, workflows).

## Operating Mode
- **LIVE**: Normal purchase order creation via Excel; supplier data may be inserted/updated.

## Database Model (Key Tables)
- `suppliers`: Master supplier data (`supplier_code` uppercase/unique). Live workflows may upsert.
- `purchase_orders`: Append-only header versions (`po_number` can have multiple versions; latest used for lines).
- `purchase_order_lines`: Lines tied to a specific header version; upload deletes/replaces existing lines for that version.

## Web UI & New Files (in `/app/`)
- `index.php`, `suppliers.php`, `purchase_orders.php`, `po_view.php` for lightweight management/reporting.
- Add helpers such as `config.php`, `db.php`, shared utilities here as needed (avoid breaking Excel/VBA contracts).

## Excel/VBA Context
- Excel sends fixed cell ranges via JSON.
- Helper functions: `NzText`, `NzNum`, `JsonEscape` (cleans tabs/line breaks/illegal chars).
- Line layouts: **STANDARD** (item/qty/pricing/discount) and **TXN** (date/desc/deposit/VAT fields/VAT flag).

## Expectations & Safety
- Keep error handling consistent; never expose secrets.
- Validate inputs; log errors with relevant metadata (not secrets).
- Maintain clear, intent-focused comments in PHP code.
- Avoid renaming/restructuring files or changing contracts without approval.