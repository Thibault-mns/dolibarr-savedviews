# SavedViews — saved list views for Dolibarr

🇫🇷 [Version française](README.fr.md)

SavedViews adds a bar of bookmark tabs above every Dolibarr list page. Each user can save the current view — search filters, selected columns, list/kanban display mode — under a name, and reopen it later in one click.

![Concept](screenshot.png)

## Features

- One-click save of the current list state: filters, visible columns, display mode (list/kanban)
- Views are **per list** and **private by default**, and can be shared with user groups or with all users (a view saved on the invoice list only shows on the invoice list; lists sharing a URL, such as products vs services or customers vs prospects, are kept separate)
- Edit a view: rename it, change its sharing, replace it with the current filters and columns
- The tab of the view currently displayed is highlighted, and flagged as modified once its filters change (one click to update it)
- Personal **default view** per list, opened automatically when you land on the list
- Works on **all** Dolibarr list pages, native and from third-party modules — no per-list configuration
- Multi-entity aware (views are scoped to the current entity)
- No core file modified; pure hook (`printCommonFooter`) + one small table

## Requirements

- Dolibarr >= 16.0
- PHP >= 7.0

## Install

1. Unzip into `htdocs/custom/` (or deploy through *Home → Setup → Modules → Deploy an external module*)
2. Enable **SavedViews** in the module list
3. Open any list page: a `+` button appears under the page title — set your filters/columns, click `+`, name the view

> **Upgrading from 1.1.x**: version 1.2.0 adds a column, a table and the `manage` right: disable then re-enable SavedViews.
>
> **Upgrading from 1.0.x**: version 1.1.0 introduces permissions. They are inserted into `llx_rights_def` when the module is enabled, so disable then re-enable SavedViews, then grant the two rights to your users or groups.

## Permissions

| Right | Grants |
|-------|--------|
| `read` | See the saved view tabs and apply them |
| `create` | Save a new view, edit or delete one of its own views |
| `manage` | Edit and delete the views shared by other users (never their private views) |

`read` and `create` are granted by default to newly created users; all three go to admin users when the module is enabled. Without `read` the module injects nothing at all; without `create` the tabs are read-only (no `+` button, no edit or delete in the tab menu), but a shared view can still be chosen as default view. Every AJAX action re-checks the right server-side.

Shared views are applied by other users, so their data is sanitized on save and again before being applied: only a relative URL of the same list is kept, without any `action`, `confirm`, `token`, `massaction` or `button_*` parameter.

Note on per-list access: a saved view holds nothing but the search parameters of a page the user was already allowed to open, and applying one is a plain redirect to that URL — so Dolibarr's own page-level permission check still governs what the user can actually see.

## How it works

The module hooks `printCommonFooter` (context `all`) and injects a small JS/CSS payload. The JS only activates when the page is an actual list (presence of the `searchFormList` form). Views are stored in `llx_savedviews` (per author, per entity, sharing groups in `llx_savedviews_usergroup`, keyed on the page path plus its `type` discriminator). Applying a view redirects to the saved URL of the same list with all filter parameters. The default view is applied by the `doActions` hook with a redirect, before the list is computed.

AJAX exchanges use the response shape of the core `JsonResponse` class (`result`, `msg`, `newToken`, `data`); the class itself is used when the Dolibarr version provides it (>= 24), with an identical fallback payload on older versions.

## License

GPL v3+. See COPYING.
