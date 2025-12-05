# Changelog

- 2025-02-02: Added configuration, database helper, authentication utilities, and a session-based login page for the admin backend.
- 2025-02-03: Added SQL to create the admin_users table and a CLI helper to seed an admin account.
- 2025-02-04: Redesigned the admin dashboard layout with sidebar navigation, theme toggle, and header actions for enterprise usability.
- 2025-02-05: Split dashboard into reusable header/sidebar/content partials with AJAX-driven navigation to avoid full page reloads.
- 2025-02-05: Moved admin layout styling into a dedicated CSS file for reuse and easier maintenance.
- 2025-02-06: Added order book metadata table definition, UI filter to view purchase orders by selected book, and SQL seeding from existing purchase orders.
- 2025-02-07: Extended order book metadata to include Description 2 and Qty fields and displayed them in the purchase orders view.
- 2025-02-08: Added order book management view with inline editing, update endpoint, and navigation entry.
