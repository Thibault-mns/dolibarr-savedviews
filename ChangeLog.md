# ChangeLog

## 1.2.0

- **Sharing**: a view can be private (default), shared with user groups or shared with all users of the entity. Shared views show a group or globe icon and the author name in their tooltip.
- **Actions menu** on each tab (always visible ⋮ button, keyboard accessible) instead of the edit and delete icons that were too close to each other: *Edit…* opens a dialog to rename the view, change its sharing or replace it with the current filters and columns; *Use as my default view* toggles the default view in AJAX; *Delete…* comes last, in red, and asks for confirmation.
- **Default view per user**: any visible view (own or shared) can become the user's default view of a list. It is opened automatically, server-side, when the user lands on the list without filters coming from another page.
- **Current view feedback**: the tab of the view the list currently shows is highlighted with the theme color and a bold label (`aria-current`). Once its filters or columns are changed, the tab turns dashed and italic with a dot, and its menu offers *Update with the current filters*. Filter values `-1` ("all") count as no filter in the comparison. Shared views lose their dashed border, the globe/group icon is enough.
- Tooltips on the icons only (not on the label): default star, sharing icon ("Shared with all users" / "Shared with user groups", plus the author for views shared by someone else) and the "modified" dot, with the dark jQuery tooltip theme of Dolibarr 24 (`jquery-tooltip --dark-mode`, backported in `backport/v24/theme/tooltips.css` for older versions). Started on first hover or focus, content escaped (names are typed by users).
- New right `manage` (not granted by default): modify and delete the views shared by other users. Private views stay private.
- Save, edit and delete use Dolibarr jQuery UI dialogs instead of the browser `prompt()` / `confirm()`.
- Security: view data is sanitized server-side (relative URL of the same page only, `action` / `confirm` / `token` / `massaction` / `button_*` parameters dropped, size limits), and again in the browser before applying a view, since shared views are applied by other users.
- Security: write actions are POST only, database errors are logged instead of being returned to the browser, the page configuration is JSON-encoded with the HEX flags.
- Security: the injected `<script>` tags carry the Content Security Policy nonce (`getNonce()`, Dolibarr >= 18).
- Unused `queryParams`, `columns` and `columnCheckboxes` are no longer stored.
- Translations: de_DE, es_ES, it_IT added.
- ⚠️ **Upgrading from 1.1.x**: disable then re-enable SavedViews to add the `scope` column, the `llx_savedviews_usergroup` table and the `manage` right.

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
