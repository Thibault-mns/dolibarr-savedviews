# ChangeLog

## 1.1.0

- **Permissions**: two rights added — `read` (see and apply saved views) and `create` (save, rename, delete own views). Both granted by default to new users.
  - Checked server-side in `ajax/savedviews.php` (every action) and in the `printCommonFooter` hook (nothing is injected without `read`).
  - Without `create`, the `+` button and the tab delete crosses are not rendered.
  - ⚠️ **Upgrading from 1.0.x**: rights are written to `llx_rights_def` when the module is enabled. Disable then re-enable SavedViews (*Home → Setup → Modules*) so the two new permissions appear, then grant them to your users/groups (*Users & Groups → user/group → Permissions*).
- AJAX responses normalized on the core `JsonResponse` shape (`result` / `msg` / `newToken` / `data`), using `DOL_DOCUMENT_ROOT/core/class/jsonResponse.class.php` when running on Dolibarr >= 24 and falling back to the same keys on older versions
- Proper HTTP status codes on errors (400 / 401 / 403 / 404 / 500)
- CSRF: the token is now really compared against `currentToken()` for every action instead of only being checked for presence (`main.inc.php` only enforces it when `MAIN_SECURITY_CSRF_WITH_TOKEN` is enabled, which is not the default); the token injected into the page switched from `newToken()` to `currentToken()`, the documented value for AJAX calls
- `printCommonFooter` injects its payload only once per page
- View labels truncated to the 128 characters of the `label` column

## 1.0.0

- Initial public release
- Save/restore per-user views (filters, columns, list/kanban mode) on all list pages
- Views keyed on page path + `type` parameter so lists sharing a URL (products/services, customers/prospects/suppliers) each keep their own views
- List-page detection restricted to real list pages (`searchFormList` form or filter row inside a list table)
- Same-origin check before applying a saved view URL
- Works installed in `htdocs/custom/` or `htdocs/`
