# ChangeLog

## 1.0.0

- Initial public release
- Save/restore per-user views (filters, columns, list/kanban mode) on all list pages
- Views keyed on page path + `type` parameter so lists sharing a URL (products/services, customers/prospects/suppliers) each keep their own views
- List-page detection restricted to real list pages (`searchFormList` form or filter row inside a list table)
- Same-origin check before applying a saved view URL
- Works installed in `htdocs/custom/` or `htdocs/`
