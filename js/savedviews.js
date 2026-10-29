/**
 * SavedViews - JavaScript for managing saved views in Dolibarr lists
 */
(function() {
    'use strict';

    // The dialogs rely on jQuery UI, loaded by Dolibarr on every page
    if (typeof window.savedviews_config === 'undefined' || !window.jQuery || typeof jQuery.fn.dialog !== 'function') {
        return;
    }

    const config = window.savedviews_config;

    /**
     * Get the title row element
     */
    function getTitleRow() {
        return document.querySelector('.table-fiche-title .toptitle');
    }

    // Same list as SavedView::FORBIDDEN_PARAMS: a shared view is applied by other users,
    // it must only ever carry list filters, never an action
    const FORBIDDEN_PARAMS = ['action', 'massaction', 'confirm', 'token', 'cancel', 'formfilteraction', 'toselect', 'sendto', 'backtopage'];

    const SCOPE_PRIVATE = 0;
    const SCOPE_USERGROUP = 1;
    const SCOPE_PUBLIC = 2;

    // Last view applied on this list, per browser tab. Only a display aid (it tells a
    // modified view from no view at all): everything works without it.
    const LAST_APPLIED_KEY = 'savedviews_last_applied:' + config.currentPage;

    function getLastApplied() {
        try {
            return parseInt(window.sessionStorage.getItem(LAST_APPLIED_KEY), 10) || 0;
        } catch (e) {
            return 0;
        }
    }

    function setLastApplied(viewId) {
        try {
            if (viewId) {
                window.sessionStorage.setItem(LAST_APPLIED_KEY, String(viewId));
            } else {
                window.sessionStorage.removeItem(LAST_APPLIED_KEY);
            }
        } catch (e) {
            // Storage unavailable (private mode, blocked site data): exact match only
        }
    }

    /**
     * Whether a query parameter may be stored in a view or replayed from it
     */
    function isAllowedParam(name) {
        if (!/^[A-Za-z0-9_\-.]+(\[[A-Za-z0-9_\-.]*\])?$/.test(name)) {
            return false;
        }
        const base = name.replace(/\[.*$/, '').toLowerCase();
        return FORBIDDEN_PARAMS.indexOf(base) === -1 && base.indexOf('button_') !== 0;
    }

    /**
     * Capture current view state (filters, columns, display mode)
     */
    function captureViewState() {
        const state = {
            url: '',
            displayMode: 'common',
            filters: {}
        };

        const urlParams = new URLSearchParams(window.location.search);

        // Detect display mode (list or kanban)
        if (urlParams.has('mode')) {
            state.displayMode = urlParams.get('mode');
        } else {
            // Check active button
            const activeMode = document.querySelector('.btnTitleSelected');
            if (activeMode && activeMode.href && activeMode.href.includes('mode=kanban')) {
                state.displayMode = 'kanban';
            }
        }

        function captureInput(input, skipIfCaptured) {
            if (!input.name || !isAllowedParam(input.name)) return;
            if (skipIfCaptured && state.filters[input.name] !== undefined) return;

            if (input.tagName === 'SELECT' && input.multiple) {
                // Multiselect (arrays like search_category_order_list[])
                const selectedValues = Array.from(input.selectedOptions).map(opt => opt.value);
                if (selectedValues.length > 0) {
                    state.filters[input.name] = selectedValues;
                }
            } else if (input.type === 'checkbox' || input.type === 'radio') {
                if (input.checked) {
                    state.filters[input.name] = input.value || '1';
                }
            } else if (input.value !== undefined && input.value !== '') {
                // Keep -1 as valid value (used for "all" or specific status filters)
                state.filters[input.name] = input.value;
            }
        }

        // Filter row of the list table
        document.querySelectorAll('tr.liste_titre_filter input, tr.liste_titre_filter select').forEach(input => captureInput(input, false));

        // Filters above the table (category selects, user selects, etc.)
        document.querySelectorAll('div.liste_titre input, div.liste_titre select').forEach(input => captureInput(input, false));

        // All search_* inputs of the main form (status, billed, etc.)
        const searchForm = document.querySelector('form[name="searchFormList"], form#searchFormList');
        if (searchForm) {
            searchForm.querySelectorAll('input[name^="search_"], select[name^="search_"]').forEach(input => captureInput(input, true));
        }

        // Selected fields (visible columns)
        const selectedFieldsInput = document.querySelector('input.selectedfields[name="selectedfields"]');
        if (selectedFieldsInput && selectedFieldsInput.value) {
            state.selectedFields = selectedFieldsInput.value;
        }

        // Relative URL of this page with all filter parameters
        const params = new URLSearchParams();
        if (state.displayMode && state.displayMode !== 'common') {
            params.set('mode', state.displayMode);
        }
        Object.keys(state.filters).forEach(name => {
            const value = state.filters[name];
            if (Array.isArray(value)) {
                value.forEach(v => params.append(name, v));
            } else if (value !== undefined && value !== '') {
                params.set(name, value);
            }
        });
        if (urlParams.has('contextpage')) {
            params.set('contextpage', urlParams.get('contextpage'));
        }
        const queryString = params.toString();
        state.url = window.location.pathname + (queryString ? '?' + queryString : '');

        return state;
    }

    /**
     * Comparable form of a view state: filters (sorted, as strings), columns, display mode
     */
    function stateSignature(viewData) {
        if (!viewData || typeof viewData !== 'object') {
            return '';
        }
        const filters = viewData.filters || {};
        // "-1" is Dolibarr's "all" value of select filters: same list as no filter at all
        const isEmpty = value => value === undefined || value === null || String(value) === '' || String(value) === '-1';
        const parts = [];
        Object.keys(filters).filter(isAllowedParam).sort().forEach(name => {
            const value = filters[name];
            const normalized = Array.isArray(value) ? value.filter(v => !isEmpty(v)).map(String).sort().join('|') : (isEmpty(value) ? '' : String(value));
            if (normalized !== '') {
                parts.push(name + '=' + normalized);
            }
        });
        return JSON.stringify([parts, viewData.selectedFields || '', viewData.displayMode || 'common']);
    }

    /**
     * Which view the list currently shows
     *
     * @return {{id: number, modified: boolean}} id 0 when no view matches nor was applied
     */
    function getCurrentViewState() {
        const current = stateSignature(captureViewState());
        const matches = config.views.filter(v => stateSignature(v.view_data) === current).map(v => v.id);
        const last = getLastApplied();

        if (matches.length > 0) {
            // Several views may hold the same filters: prefer the one the user applied.
            // Remembered so that changing a filter afterwards shows the view as modified,
            // also when it was opened server-side as default view.
            const id = matches.indexOf(last) !== -1 ? last : matches[0];
            setLastApplied(id);
            return { id: id, modified: false };
        }
        if (last && config.views.some(v => v.id === last)) {
            return { id: last, modified: true };
        }
        return { id: 0, modified: false };
    }

    /**
     * Apply a saved view state
     */
    function applyViewState(viewData) {
        if (!viewData) {
            return;
        }

        // Redirect to the saved URL. Only this very page is accepted, and action
        // parameters are dropped: a view may have been saved by another user.
        if (viewData.url) {
            try {
                const saved = new URL(viewData.url, window.location.origin);
                if (saved.origin === window.location.origin && saved.pathname === window.location.pathname) {
                    const target = new URL(window.location.pathname, window.location.origin);
                    saved.searchParams.forEach((value, name) => {
                        if (isAllowedParam(name)) {
                            target.searchParams.append(name, value);
                        }
                    });
                    // Columns are not applied by the filter params alone: Dolibarr only
                    // restores them when selectedfields comes with formfilteraction
                    if (viewData.selectedFields && !target.searchParams.has('selectedfields')) {
                        target.searchParams.set('selectedfields', viewData.selectedFields);
                        target.searchParams.set('formfilteraction', 'listafterchangingselectedfields');
                    }
                    window.location.href = target.href;
                    return;
                }
            } catch (e) {
                // Invalid stored URL: fall through to filter-based rebuild
            }
        }

        // Fallback: build URL from filters if url not saved
        if (viewData.filters) {
            const params = new URLSearchParams();

            if (viewData.displayMode && viewData.displayMode !== 'common') {
                params.set('mode', viewData.displayMode);
            }

            Object.keys(viewData.filters).forEach(name => {
                if (!isAllowedParam(name)) return;
                const value = viewData.filters[name];
                if (Array.isArray(value)) {
                    value.forEach(v => params.append(name, v));
                } else if (value !== undefined && value !== '') {
                    params.set(name, value);
                }
            });

            if (viewData.selectedFields) {
                params.set('selectedfields', viewData.selectedFields);
                params.set('formfilteraction', 'listafterchangingselectedfields');
            }

            const queryString = params.toString();
            window.location.href = window.location.pathname + (queryString ? '?' + queryString : '');
        }
    }

    /**
     * POST an action to the AJAX handler.
     * Response shape follows core JsonResponse: {result, msg, newToken, data}
     */
    function callAjax(fields) {
        const formData = new FormData();
        formData.append('token', config.token);
        Object.keys(fields).forEach(name => {
            const value = fields[name];
            if (Array.isArray(value)) {
                value.forEach(v => formData.append(name + '[]', v));
            } else if (value !== undefined && value !== null) {
                formData.append(name, value);
            }
        });

        return fetch(config.ajaxUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(json => {
            if (json.result != 1) {
                throw new Error(json.msg || config.labels.error);
            }
            return json.data || {};
        });
    }

    /**
     * Store a view returned by the server and keep a single default view
     */
    function storeView(view) {
        if (view.is_default) {
            config.views.forEach(v => { v.is_default = false; });
        }
        const index = config.views.findIndex(v => v.id === view.id);
        if (index === -1) {
            config.views.push(view);
        } else {
            config.views[index] = view;
        }
    }

    /**
     * Show a notification message: Dolibarr's jnotify when available, own toast otherwise
     */
    function showNotification(message, type) {
        if (window.jQuery && typeof jQuery.jnotify === 'function') {
            // jnotify renders HTML: escape the message. Same presets as setEventMessages()
            const safe = jQuery('<div>').text(message).html();
            jQuery.jnotify(safe, type === 'error' ? 'error' : 3000, type === 'error', { remove: function () {} });
            return;
        }

        const notification = document.createElement('div');
        notification.className = 'savedviews-notification savedviews-notification-' + type;
        notification.textContent = message;
        document.body.appendChild(notification);

        setTimeout(() => {
            notification.classList.add('savedviews-notification-fade');
            setTimeout(() => notification.remove(), 300);
        }, 3000);
    }

    /**
     * Create an element with a class and an optional text
     */
    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    /**
     * Build one labelled row of the view dialog
     */
    function dialogRow(labelText, field, forId) {
        const row = el('div', 'savedviews-dialog-row');
        const label = el('label', 'savedviews-dialog-label', labelText);
        if (forId) label.htmlFor = forId;
        row.appendChild(label);
        const cell = el('div', 'savedviews-dialog-field');
        cell.appendChild(field);
        row.appendChild(cell);
        return row;
    }

    /**
     * Open the save / edit dialog
     *
     * @param {Object|null} view Existing view to edit, null to save the current list state
     */
    function openViewDialog(view) {
        const isNew = !view;
        const editable = isNew ? !!config.canCreate : !!view.can_edit;
        const scope = isNew ? SCOPE_PRIVATE : view.scope;
        const groups = isNew ? [] : (view.usergroups || []);

        const form = el('form', 'savedviews-dialog');
        form.addEventListener('submit', e => e.preventDefault());

        if (!isNew && !view.mine) {
            form.appendChild(el('p', 'opacitymedium', config.labels.sharedBy + ' ' + view.author));
        }

        // Name
        const nameInput = el('input', 'flat minwidth300 maxwidth100pc');
        nameInput.type = 'text';
        nameInput.id = 'savedviews-name';
        nameInput.maxLength = 128;
        nameInput.value = isNew ? '' : view.label;
        nameInput.disabled = !editable;
        form.appendChild(dialogRow(config.labels.viewName, nameInput, nameInput.id));

        // Visibility
        let scopeInputs = [];
        let groupSelect = null;
        let groupRow = null;
        if (editable) {
            const scopeWrap = el('div', 'savedviews-dialog-scopes');
            [[SCOPE_PRIVATE, config.labels.scopePrivate], [SCOPE_USERGROUP, config.labels.scopeUserGroup], [SCOPE_PUBLIC, config.labels.scopePublic]].forEach(([value, text]) => {
                const label = el('label', 'savedviews-dialog-scope');
                const radio = el('input');
                radio.type = 'radio';
                radio.name = 'savedviews-scope';
                radio.value = value;
                radio.checked = (value === scope);
                // A view without any group to share with cannot use the group scope
                if (value === SCOPE_USERGROUP && config.userGroups.length === 0) {
                    radio.disabled = true;
                }
                label.appendChild(radio);
                label.appendChild(document.createTextNode(' ' + text));
                scopeWrap.appendChild(label);
                scopeInputs.push(radio);
            });
            form.appendChild(dialogRow(config.labels.viewScope, scopeWrap));

            groupSelect = el('select', 'flat minwidth300 maxwidth100pc');
            groupSelect.id = 'savedviews-groups';
            groupSelect.multiple = true;
            config.userGroups.forEach(group => {
                const option = el('option', '', group.label);
                option.value = group.id;
                option.selected = groups.indexOf(group.id) !== -1;
                groupSelect.appendChild(option);
            });
            groupRow = dialogRow(config.labels.userGroups, groupSelect, groupSelect.id);
            form.appendChild(groupRow);

            const toggleGroups = () => {
                const checked = scopeInputs.find(r => r.checked);
                groupRow.style.display = (checked && parseInt(checked.value, 10) === SCOPE_USERGROUP) ? '' : 'none';
            };
            scopeInputs.forEach(r => r.addEventListener('change', toggleGroups));
            toggleGroups();
        } else if (!isNew) {
            form.appendChild(el('p', 'opacitymedium', config.labels.readOnlyView));
        }

        // Replace the stored state with the current list state
        let replaceInput = null;
        if (!isNew && editable) {
            replaceInput = el('input');
            replaceInput.type = 'checkbox';
            replaceInput.id = 'savedviews-replace';
            form.appendChild(dialogRow(config.labels.replaceWithCurrent, replaceInput, replaceInput.id));
        }

        // Personal default view of this list
        const defaultInput = el('input');
        defaultInput.type = 'checkbox';
        defaultInput.id = 'savedviews-default';
        defaultInput.checked = !isNew && !!view.is_default;
        const defaultRow = dialogRow(config.labels.isDefault, defaultInput, defaultInput.id);
        defaultRow.title = config.labels.isDefaultHelp;
        form.appendChild(defaultRow);

        const $dialog = jQuery('<div>').append(form);

        function submit() {
            const fields = { is_default: defaultInput.checked ? 1 : 0 };
            let action = 'setdefault';

            if (editable) {
                const label = nameInput.value.trim();
                if (!label) {
                    showNotification(config.labels.nameRequired, 'error');
                    nameInput.focus();
                    return;
                }
                const checked = scopeInputs.find(r => r.checked);
                const scopeValue = checked ? parseInt(checked.value, 10) : SCOPE_PRIVATE;
                const groupIds = groupSelect ? Array.from(groupSelect.selectedOptions).map(o => o.value) : [];
                if (scopeValue === SCOPE_USERGROUP && groupIds.length === 0) {
                    showNotification(config.labels.groupRequired, 'error');
                    return;
                }

                fields.label = label;
                fields.scope = scopeValue;
                fields.usergroups = scopeValue === SCOPE_USERGROUP ? groupIds : [];
                action = isNew ? 'save' : 'update';

                if (isNew) {
                    fields.page_url = config.currentPage;
                    fields.view_data = JSON.stringify(captureViewState());
                } else if (replaceInput && replaceInput.checked) {
                    fields.view_data = JSON.stringify(captureViewState());
                }
            }

            fields.action = action;
            if (!isNew) {
                fields.id = view.id;
            }

            callAjax(fields)
                .then(data => {
                    storeView(data.view);
                    if (fields.view_data) {
                        setLastApplied(data.view.id);
                    }
                    renderViewTabs();
                    showNotification(config.labels.viewSaved, 'success');
                    $dialog.dialog('close');
                })
                .catch(error => {
                    console.error('SavedViews error:', error);
                    showNotification(error.message || config.labels.error, 'error');
                });
        }

        form.addEventListener('submit', submit);

        // Cancel on the left, primary action on the right
        $dialog.dialog({
            title: isNew ? config.labels.saveView : config.labels.editView,
            modal: true,
            width: Math.min(560, window.innerWidth - 20),
            resizable: false,
            closeText: '',
            buttons: [
                { text: config.labels.cancel, 'class': 'button button-cancel', click: () => $dialog.dialog('close') },
                { text: config.labels.save, 'class': 'button button-save', click: submit }
            ],
            open: () => {
                if (groupSelect && typeof jQuery.fn.select2 === 'function') {
                    jQuery(groupSelect).select2({ dropdownParent: $dialog, width: 'resolve', dropdownAutoWidth: true });
                }
                if (editable) {
                    nameInput.focus();
                }
            },
            close: () => $dialog.dialog('destroy').remove()
        });
    }

    /**
     * Ask for confirmation then delete a view
     */
    function confirmDeleteView(view) {
        const $dialog = jQuery('<div>').append(el('p', '', config.labels.confirmDelete));

        $dialog.dialog({
            title: config.labels.deleteView + ' : ' + view.label,
            modal: true,
            width: Math.min(460, window.innerWidth - 20),
            resizable: false,
            closeText: '',
            buttons: [
                { text: config.labels.cancel, 'class': 'button button-cancel', click: () => $dialog.dialog('close') },
                {
                    text: config.labels.delete,
                    'class': 'button butActionDelete',
                    click: () => {
                        callAjax({ action: 'delete', id: view.id })
                            .then(() => {
                                config.views = config.views.filter(v => v.id !== view.id);
                                renderViewTabs();
                                showNotification(config.labels.viewDeleted, 'success');
                            })
                            .catch(error => {
                                console.error('SavedViews error:', error);
                                showNotification(error.message || config.labels.error, 'error');
                            });
                        $dialog.dialog('close');
                    }
                }
            ],
            close: () => $dialog.dialog('destroy').remove()
        });
    }

    /**
     * Check if current page is a list page (index)
     */
    function isListPage() {
        // The main list search form is the reliable marker of a Dolibarr list page.
        // Do NOT match on div.liste_titre alone: that class also appears on card
        // and admin pages, which would inject the bar where it does not belong.
        const searchForm = document.querySelector('form[name="searchFormList"], form#searchFormList');
        if (searchForm) return true;

        // Fallback: a filter row inside an actual list table
        const filterRow = document.querySelector('table.liste tr.liste_titre_filter');
        if (filterRow) return true;

        return false;
    }

    let openMenu = null;

    /**
     * Close the open view menu, if any
     *
     * @param {boolean} restoreFocus Give the focus back to the menu button
     */
    function closeViewMenu(restoreFocus) {
        if (!openMenu) {
            return;
        }
        const { menu, button } = openMenu;
        openMenu = null;
        menu.remove();
        button.setAttribute('aria-expanded', 'false');
        document.removeEventListener('mousedown', onDocumentMouseDown, true);
        window.removeEventListener('scroll', onViewportChange, true);
        window.removeEventListener('resize', onViewportChange);
        if (restoreFocus) {
            button.focus();
        }
    }

    function onDocumentMouseDown(e) {
        if (openMenu && !openMenu.menu.contains(e.target) && !openMenu.button.contains(e.target)) {
            closeViewMenu(false);
        }
    }

    function onViewportChange() {
        closeViewMenu(false);
    }

    /**
     * Switch the personal default view from the menu (AJAX, optimistic UI)
     */
    function toggleDefaultView(view) {
        const previous = config.views.map(v => ({ id: v.id, is_default: v.is_default }));
        const makeDefault = !view.is_default;

        config.views.forEach(v => { v.is_default = makeDefault && v.id === view.id; });
        renderViewTabs();

        callAjax({ action: 'setdefault', id: view.id, is_default: makeDefault ? 1 : 0 })
            .then(data => {
                storeView(data.view);
                renderViewTabs();
            })
            .catch(error => {
                // Roll back the optimistic change
                previous.forEach(p => {
                    const v = config.views.find(x => x.id === p.id);
                    if (v) v.is_default = p.is_default;
                });
                renderViewTabs();
                console.error('SavedViews error:', error);
                showNotification(error.message || config.labels.error, 'error');
            });
    }

    /**
     * Replace the stored state of a view with the current filters and columns
     */
    function updateViewWithCurrent(view) {
        callAjax({ action: 'update', id: view.id, view_data: JSON.stringify(captureViewState()) })
            .then(data => {
                storeView(data.view);
                setLastApplied(data.view.id);
                renderViewTabs();
                showNotification(config.labels.viewSaved, 'success');
            })
            .catch(error => {
                console.error('SavedViews error:', error);
                showNotification(error.message || config.labels.error, 'error');
            });
    }

    /**
     * Open (or close) the actions menu of a view
     */
    function toggleViewMenu(view, button, modified) {
        if (openMenu && openMenu.button === button) {
            closeViewMenu(true);
            return;
        }
        closeViewMenu(false);

        const items = [];
        if (view.can_edit && modified) {
            items.push({ icon: 'fa-sync-alt', text: config.labels.updateWithCurrent, run: () => updateViewWithCurrent(view) });
        }
        if (view.can_edit) {
            items.push({ icon: 'fa-pencil-alt', text: config.labels.menuEdit, run: () => openViewDialog(view) });
        }
        items.push({
            icon: 'fa-star',
            text: view.is_default ? config.labels.unsetDefault : config.labels.setDefault,
            run: () => toggleDefaultView(view)
        });
        if (view.can_edit) {
            // Destructive action: last, separated, in red, and confirmed by a dialog
            items.push({ separator: true });
            items.push({ icon: 'fa-trash-alt', text: config.labels.menuDelete, danger: true, run: () => confirmDeleteView(view) });
        }

        const menu = el('div', 'savedviews-menu');
        menu.setAttribute('role', 'menu');
        menu.setAttribute('aria-label', view.label);

        const menuItems = [];
        items.forEach(item => {
            if (item.separator) {
                const sep = el('div', 'savedviews-menu-separator');
                sep.setAttribute('role', 'separator');
                menu.appendChild(sep);
                return;
            }
            const entry = el('button', 'savedviews-menu-item' + (item.danger ? ' savedviews-menu-item-danger' : ''));
            entry.type = 'button';
            entry.setAttribute('role', 'menuitem');
            entry.tabIndex = -1;
            entry.appendChild(el('span', 'fa ' + item.icon + ' savedviews-menu-icon'));
            entry.appendChild(document.createTextNode(item.text));
            entry.addEventListener('click', () => {
                closeViewMenu(false);
                item.run();
            });
            menu.appendChild(entry);
            menuItems.push(entry);
        });

        // Keyboard: arrows move, Home/End jump, Escape/Tab close
        menu.addEventListener('keydown', (e) => {
            const index = menuItems.indexOf(document.activeElement);
            let next = null;
            if (e.key === 'ArrowDown') next = menuItems[(index + 1) % menuItems.length];
            else if (e.key === 'ArrowUp') next = menuItems[(index - 1 + menuItems.length) % menuItems.length];
            else if (e.key === 'Home') next = menuItems[0];
            else if (e.key === 'End') next = menuItems[menuItems.length - 1];
            else if (e.key === 'Escape') { e.preventDefault(); closeViewMenu(true); return; }
            else if (e.key === 'Tab') { closeViewMenu(false); return; }
            if (next) {
                e.preventDefault();
                next.focus();
            }
        });

        // Fixed position under the button, kept inside the viewport
        document.body.appendChild(menu);
        const rect = button.getBoundingClientRect();
        const menuWidth = menu.offsetWidth;
        const menuHeight = menu.offsetHeight;
        let left = rect.left;
        if (left + menuWidth > window.innerWidth - 8) {
            left = Math.max(8, rect.right - menuWidth);
        }
        let top = rect.bottom + 4;
        if (top + menuHeight > window.innerHeight - 8 && rect.top - menuHeight - 4 > 8) {
            top = rect.top - menuHeight - 4;
        }
        menu.style.left = left + 'px';
        menu.style.top = top + 'px';

        openMenu = { menu, button };
        button.setAttribute('aria-expanded', 'true');
        document.addEventListener('mousedown', onDocumentMouseDown, true);
        window.addEventListener('scroll', onViewportChange, true);
        window.addEventListener('resize', onViewportChange);

        menuItems[0].focus();
    }

    /**
     * Icon with a tooltip
     */
    function tooltipIcon(iconClass, text) {
        const icon = el('span', 'fa ' + iconClass + ' savedviews-tab-icon savedviews-tooltip');
        icon.title = text;
        icon.setAttribute('role', 'img');
        icon.setAttribute('aria-label', text);
        return icon;
    }

    /**
     * Tooltips of the tabs: jQuery UI tooltip with the dark theme of Dolibarr 24
     * (jquery-tooltip --dark-mode, short tips of information; backported for older versions).
     * Not classfortooltip: core binds it once on page load with the large object-card bubble.
     * Started on the first hover or focus, so tabs re-rendered later need no init.
     */
    function startTooltip(element) {
        const $el = jQuery(element);
        if ($el.tooltip('instance') || !$el.attr('title')) {
            return false;
        }
        $el.tooltip({
            tooltipClass: 'jquery-tooltip --dark-mode',
            show: { collision: 'flipfit', effect: 'toggle', delay: 50, duration: 20 },
            hide: { delay: 250, duration: 20 },
            content: function () {
                // Titles hold view labels and user names typed by users: rendered as text
                return jQuery('<div>').text($el.prop('title')).html();
            }
        });
        return true;
    }

    if (!config.noMouseHover && typeof jQuery.fn.tooltip === 'function') {
        // mouseover / focusin: jQuery UI then closes it on mouseleave / focusout by itself
        jQuery(document).on('mouseover focusin', '.savedviews-tooltip', function (event) {
            if (startTooltip(this)) {
                jQuery(this).tooltip('open', event);
            }
        });
    }

    /**
     * Render the view tabs UI
     */
    function renderViewTabs() {
        closeViewMenu(false);

        // Remove existing container
        const existing = document.querySelector('.savedviews-container');
        if (existing) {
            if (typeof jQuery.fn.tooltip === 'function') {
                jQuery(existing).find('.savedviews-tooltip').each(function () {
                    if (jQuery(this).tooltip('instance')) {
                        jQuery(this).tooltip('destroy');
                    }
                });
            }
            existing.remove();
        }

        // Only show on list pages
        if (!isListPage()) {
            return;
        }

        const titleRow = getTitleRow();
        if (!titleRow) {
            return;
        }

        // Find the title table
        const titleTable = titleRow.closest('.table-fiche-title');
        if (!titleTable) {
            return;
        }

        if (!config.canCreate && config.views.length === 0) {
            // Nothing to show and nothing to create: do not display an empty bar
            return;
        }

        const container = el('div', 'savedviews-container');
        const tabsWrapper = el('div', 'savedviews-tabs');
        const currentView = getCurrentViewState();

        config.views.forEach(view => {
            const isCurrent = (view.id === currentView.id);
            const isModified = isCurrent && currentView.modified;
            const tab = el('div', 'savedviews-tab' + (view.mine ? '' : ' savedviews-tab-shared')
                + (isCurrent ? ' savedviews-tab-current' : '') + (isModified ? ' savedviews-tab-modified' : ''));
            tab.setAttribute('data-view-id', view.id);

            // Label: a real button, so the view can be applied from the keyboard too
            const tabLabel = el('button', 'savedviews-tab-label');
            tabLabel.type = 'button';
            if (view.is_default) {
                tabLabel.appendChild(tooltipIcon('fa-star', config.labels.defaultView));
            }
            if (view.scope !== SCOPE_PRIVATE) {
                // Author of a view shared by someone else goes into the sharing icon tooltip
                const sharing = view.scope === SCOPE_PUBLIC ? config.labels.sharedWithAll : config.labels.sharedWithGroups;
                tabLabel.appendChild(tooltipIcon(view.scope === SCOPE_PUBLIC ? 'fa-globe' : 'fa-users',
                    view.mine ? sharing : sharing + ' - ' + config.labels.sharedBy + ' ' + view.author));
            }
            // No tooltip on the label itself: only the icons carry one
            tabLabel.appendChild(el('span', 'savedviews-tab-text', view.label));
            if (isCurrent) {
                tabLabel.setAttribute('aria-current', 'true');
            }
            if (isModified) {
                // Text marker, not color alone
                const dot = el('span', 'savedviews-tab-modified-dot savedviews-tooltip', '\u2022');
                dot.title = config.labels.currentViewModified;
                dot.setAttribute('role', 'img');
                dot.setAttribute('aria-label', config.labels.currentViewModified);
                tabLabel.appendChild(dot);
            }
            tabLabel.addEventListener('click', () => {
                setLastApplied(view.id);
                applyViewState(view.view_data);
            });
            tab.appendChild(tabLabel);

            // Actions live in a menu: edit and delete side by side were too easy to miss-click.
            // Always visible (no hover-only control), every view has at least the default toggle.
            const menuBtn = el('button', 'savedviews-tab-menu');
            menuBtn.type = 'button';
            menuBtn.title = config.labels.viewActions;
            menuBtn.setAttribute('aria-label', config.labels.viewActions + ' : ' + view.label);
            menuBtn.setAttribute('aria-haspopup', 'menu');
            menuBtn.setAttribute('aria-expanded', 'false');
            menuBtn.appendChild(el('span', 'fa fa-ellipsis-v'));
            menuBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                toggleViewMenu(view, menuBtn, isModified);
            });
            tab.appendChild(menuBtn);

            tabsWrapper.appendChild(tab);
        });

        if (config.canCreate) {
            // "+" button to save the current state as a new view
            const addBtn = el('div', 'savedviews-add');
            addBtn.appendChild(el('span', 'fa fa-plus'));
            addBtn.title = config.labels.saveView;
            addBtn.addEventListener('click', () => openViewDialog(null));
            tabsWrapper.appendChild(addBtn);
        }

        container.appendChild(tabsWrapper);

        // Insert after the title table
        titleTable.parentNode.insertBefore(container, titleTable.nextSibling);
    }

    /**
     * Initialize the module
     */
    function init() {
        if (!isListPage()) {
            return;
        }

        // Wait for DOM to be fully ready
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', renderViewTabs);
        } else {
            renderViewTabs();
        }
    }

    // Initialize
    init();

})();
