/**
 * SavedViews - JavaScript for managing saved views in Dolibarr lists
 */
(function() {
    'use strict';

    if (typeof window.savedviews_config === 'undefined') {
        return;
    }

    const config = window.savedviews_config;

    /**
     * Get the title row element
     */
    function getTitleRow() {
        return document.querySelector('.table-fiche-title .toptitle');
    }

    /**
     * Capture current view state (columns, filters, display mode)
     */
    function captureViewState() {
        const state = {
            url: window.location.href,
            queryParams: {},
            columns: [],
            displayMode: 'common',
            filters: {}
        };

        // Parse current URL query parameters
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.forEach((value, key) => {
            state.queryParams[key] = value;
        });

        // Detect display mode (list or kanban)
        if (urlParams.has('mode')) {
            state.displayMode = urlParams.get('mode');
        } else {
            // Check active button
            const activeMode = document.querySelector('.btnTitleSelected');
            if (activeMode) {
                if (activeMode.href && activeMode.href.includes('mode=kanban')) {
                    state.displayMode = 'kanban';
                } else {
                    state.displayMode = 'common';
                }
            }
        }

        // Capture visible columns from table headers
        const headerCells = document.querySelectorAll('table.liste thead tr.liste_titre_filter th, table.liste tr.liste_titre_filter td');
        headerCells.forEach((cell, index) => {
            const input = cell.querySelector('input, select');
            if (input && input.name) {
                state.columns.push({
                    index: index,
                    name: input.name,
                    visible: true
                });
            }
        });

        // Capture filter values from tr.liste_titre_filter inputs
        const filterInputs = document.querySelectorAll('tr.liste_titre_filter input, tr.liste_titre_filter select');
        filterInputs.forEach(input => {
            if (input.name && input.value !== undefined && input.value !== '') {
                state.filters[input.name] = input.value;
            }
        });

        // Capture filter values from div.liste_titre (category selects, user selects, etc.)
        const divListeTitreInputs = document.querySelectorAll('div.liste_titre input, div.liste_titre select');
        divListeTitreInputs.forEach(input => {
            if (!input.name) return;

            // Handle multiselect (arrays like search_category_order_list[])
            if (input.tagName === 'SELECT' && input.multiple) {
                const selectedValues = Array.from(input.selectedOptions).map(opt => opt.value);
                if (selectedValues.length > 0) {
                    state.filters[input.name] = selectedValues;
                }
            } else if (input.type === 'checkbox') {
                if (input.checked) {
                    state.filters[input.name] = input.value || '1';
                }
            } else if (input.value !== undefined && input.value !== '') {
                // Keep -1 as valid value (used for "all" or specific status filters)
                state.filters[input.name] = input.value;
            }
        });

        // Capture all search_* inputs from the main form (status, billed, etc.)
        const searchForm = document.querySelector('form[name="searchFormList"], form#searchFormList');
        if (searchForm) {
            const formInputs = searchForm.querySelectorAll('input[name^="search_"], select[name^="search_"]');
            formInputs.forEach(input => {
                if (!input.name || state.filters[input.name] !== undefined) return; // Skip already captured

                if (input.tagName === 'SELECT' && input.multiple) {
                    const selectedValues = Array.from(input.selectedOptions).map(opt => opt.value);
                    if (selectedValues.length > 0) {
                        state.filters[input.name] = selectedValues;
                    }
                } else if (input.type === 'checkbox') {
                    if (input.checked) {
                        state.filters[input.name] = input.value || '1';
                    }
                } else if (input.value !== undefined && input.value !== '') {
                    state.filters[input.name] = input.value;
                }
            });
        }

        // Capture selectedfields (visible columns)
        const selectedFieldsInput = document.querySelector('input.selectedfields[name="selectedfields"]');
        if (selectedFieldsInput && selectedFieldsInput.value) {
            state.selectedFields = selectedFieldsInput.value;
        }

        // Capture column checkboxes state
        const columnCheckboxes = document.querySelectorAll('.multiselectcheckboxselectedfields input[type="checkbox"]');
        state.columnCheckboxes = {};
        columnCheckboxes.forEach(cb => {
            if (cb.value) {
                state.columnCheckboxes[cb.value] = cb.checked;
            }
        });

        // Build URL with all filter parameters
        const baseUrl = window.location.pathname;
        const params = new URLSearchParams();

        // Add display mode
        if (state.displayMode && state.displayMode !== 'common') {
            params.set('mode', state.displayMode);
        }

        // Add all captured filter values
        Object.keys(state.filters).forEach(name => {
            const value = state.filters[name];
            if (Array.isArray(value)) {
                // For multiselect, add each value
                value.forEach(v => params.append(name, v));
            } else if (value !== undefined && value !== '') {
                params.set(name, value);
            }
        });

        // Also add contextpage if present in current URL
        if (urlParams.has('contextpage')) {
            params.set('contextpage', urlParams.get('contextpage'));
        }

        // Build final URL
        const queryString = params.toString();
        state.url = window.location.origin + baseUrl + (queryString ? '?' + queryString : '');

        return state;
    }

    /**
     * Apply a saved view state
     */
    function applyViewState(viewData) {
        if (!viewData) {
            return;
        }

        // Simple and reliable: redirect to saved URL with all filters
        // (same-origin only — a stored URL must never redirect elsewhere)
        if (viewData.url) {
            try {
                const target = new URL(viewData.url, window.location.origin);
                if (target.origin === window.location.origin && (target.protocol === 'http:' || target.protocol === 'https:')) {
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

            // Add display mode
            if (viewData.displayMode && viewData.displayMode !== 'common') {
                params.set('mode', viewData.displayMode);
            }

            // Add all filter values
            Object.keys(viewData.filters).forEach(name => {
                const value = viewData.filters[name];
                if (Array.isArray(value)) {
                    value.forEach(v => params.append(name, v));
                } else if (value !== undefined && value !== '') {
                    params.set(name, value);
                }
            });

            // Add selectedfields for columns
            if (viewData.selectedFields) {
                params.set('selectedfields', viewData.selectedFields);
                params.set('formfilteraction', 'listafterchangingselectedfields');
            }

            const queryString = params.toString();
            window.location.href = window.location.pathname + (queryString ? '?' + queryString : '');
        }
    }

    /**
     * Save current view via AJAX
     */
    function saveView(label) {
        const viewState = captureViewState();

        const formData = new FormData();
        formData.append('action', 'save');
        formData.append('token', config.token);
        formData.append('label', label);
        formData.append('page_url', config.currentPage);
        formData.append('view_data', JSON.stringify(viewState));

        fetch(config.ajaxUrl, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                config.views.push(data.view);
                renderViewTabs();
                showNotification(config.labels.viewSaved, 'success');
            } else {
                showNotification(data.error || config.labels.error, 'error');
            }
        })
        .catch(error => {
            console.error('SavedViews error:', error);
            showNotification(config.labels.error, 'error');
        });
    }

    /**
     * Delete a saved view via AJAX
     */
    function deleteView(viewId) {
        if (!confirm(config.labels.confirmDelete)) {
            return;
        }

        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('token', config.token);
        formData.append('id', viewId);

        fetch(config.ajaxUrl, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                config.views = config.views.filter(v => v.id !== viewId);
                renderViewTabs();
                showNotification(config.labels.viewDeleted, 'success');
            } else {
                showNotification(data.error || config.labels.error, 'error');
            }
        })
        .catch(error => {
            console.error('SavedViews error:', error);
            showNotification(config.labels.error, 'error');
        });
    }

    /**
     * Show a notification message (custom toast, not Dolibarr's setEventMessages)
     */
    function showNotification(message, type) {
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
     * Prompt for view label and save
     */
    function promptSaveView() {
        const label = prompt(config.labels.enterLabel);
        if (label && label.trim()) {
            saveView(label.trim());
        }
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

    /**
     * Render the view tabs UI
     */
    function renderViewTabs() {
        // Remove existing container
        const existing = document.querySelector('.savedviews-container');
        if (existing) {
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

        // Create container
        const container = document.createElement('div');
        container.className = 'savedviews-container';

        // Create tabs wrapper
        const tabsWrapper = document.createElement('div');
        tabsWrapper.className = 'savedviews-tabs';

        // Add existing view tabs
        config.views.forEach(view => {
            const tab = document.createElement('div');
            tab.className = 'savedviews-tab';
            tab.setAttribute('data-view-id', view.id);

            const tabLabel = document.createElement('span');
            tabLabel.className = 'savedviews-tab-label';
            tabLabel.textContent = view.label;
            tabLabel.title = view.label;
            tabLabel.addEventListener('click', () => {
                applyViewState(view.view_data);
            });

            const deleteBtn = document.createElement('span');
            deleteBtn.className = 'savedviews-tab-delete';
            deleteBtn.innerHTML = '&times;';
            deleteBtn.title = config.labels.deleteView;
            deleteBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                deleteView(view.id);
            });

            tab.appendChild(tabLabel);
            tab.appendChild(deleteBtn);
            tabsWrapper.appendChild(tab);
        });

        // Add "+" button to save new view
        const addBtn = document.createElement('div');
        addBtn.className = 'savedviews-add';
        addBtn.innerHTML = '<span class="fa fa-plus"></span>';
        addBtn.title = config.labels.saveView;
        addBtn.addEventListener('click', promptSaveView);

        tabsWrapper.appendChild(addBtn);
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
