# SavedViews — saved list views for Dolibarr

SavedViews adds a bar of bookmark tabs above every Dolibarr list page. Each user can save the current view — search filters, selected columns, list/kanban display mode — under a name, and reopen it later in one click.

![Concept](docs/screenshot.png)

## Features

- One-click save of the current list state: filters, visible columns, display mode (list/kanban)
- Views are **per user** and **per list** (a view saved on the invoice list only shows on the invoice list; lists sharing a URL, such as products vs services or customers vs prospects, are kept separate)
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

## How it works

The module hooks `printCommonFooter` (context `all`) and injects a small JS/CSS payload. The JS only activates when the page is an actual list (presence of the `searchFormList` form). Views are stored in `llx_savedviews` (per user, per entity, keyed on the page path plus its `type` discriminator). Applying a view redirects to the saved same-origin URL with all filter parameters.

## License

GPL v3+. See COPYING.
