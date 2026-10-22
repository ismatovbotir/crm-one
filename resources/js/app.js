import './label-designer.js';

/*
 * Alpine components for the CSP-safe Alpine build (config livewire.csp_safe): expressions in
 * Blade stay simple (property names, method calls); logic lives here, so script-src needs no
 * 'unsafe-eval'. Livewire bundles Alpine and starts it after this module has run.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('copyField', () => ({
        copied: false,

        get notCopied() {
            return !this.copied;
        },

        selectAll(event) {
            event.target.select();
        },

        copy() {
            const field = this.$refs.token;

            if (!field) {
                return;
            }

            field.select();
            navigator.clipboard?.writeText(field.value).then(() => {
                this.copied = true;
            }).catch(() => {});
        },
    }));

    // Lead capture form (Spec 04 section 16): unsaved-changes warning and keyboard shortcuts.
    window.Alpine.data('leadForm', () => ({
        dirty: false,

        init() {
            this.guard = (event) => {
                if (this.dirty) {
                    event.preventDefault();
                    event.returnValue = '';
                }
            };
            window.addEventListener('beforeunload', this.guard);
            // Step-by-step mode (Spec 04 16.4): move focus to the error summary when there are
            // errors, else to the heading of the step that is now shown.
            this.onStep = () => {
                this.$nextTick(() => {
                    const target = this.$root.querySelector('[data-error-summary]') || this.$root.querySelector('[data-step-heading]');

                    if (target) {
                        target.focus();
                    }
                });
            };
            window.addEventListener('lead-step-changed', this.onStep);
        },

        destroy() {
            window.removeEventListener('beforeunload', this.guard);
            window.removeEventListener('lead-step-changed', this.onStep);
        },

        markDirty() {
            this.dirty = true;
        },

        markClean() {
            this.dirty = false;
        },

        shortcutSave() {
            this.dirty = false;
            this.$wire.save('stay');
        },

        shortcutSaveAnother() {
            this.dirty = false;
            this.$wire.save('another');
        },
    }));

    // Offer builder catalog search: after "Add" the search is cleared and keeps the focus.
    window.Alpine.data('itemPicker', () => ({
        init() {
            this.onFocus = () => this.$nextTick(() => this.$refs.search && this.$refs.search.focus());
            window.addEventListener('item-picker-focus', this.onFocus);
        },

        destroy() {
            window.removeEventListener('item-picker-focus', this.onFocus);
        },
    }));

    // Accessible tabs (WAI-ARIA tabs pattern): arrow keys, Home and End move between tabs and
    // activate them; only the selected tab is in the tab order.
    window.Alpine.data('tabList', () => ({
        init() {
            this.sync();
            new MutationObserver(() => this.sync()).observe(this.$el, { subtree: true, attributes: true, attributeFilter: ['aria-selected'] });
        },

        tabs() {
            return [...this.$el.querySelectorAll('[role="tab"]')].filter((tab) => !tab.disabled);
        },

        sync() {
            this.tabs().forEach((tab) => tab.setAttribute('tabindex', tab.getAttribute('aria-selected') === 'true' ? '0' : '-1'));
        },

        onKey(event) {
            const tabs = this.tabs();
            const index = tabs.indexOf(document.activeElement);

            if (index === -1) {
                return;
            }

            const target = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: tabs.length - 1 }[event.key];

            if (target === undefined) {
                return;
            }

            event.preventDefault();
            const next = tabs[(target + tabs.length) % tabs.length];
            next.focus();
            next.click();
        },
    }));

    // Modal dialog: focus moves into the dialog, Tab is trapped inside, focus returns to the
    // element that opened it when the dialog disappears.
    window.Alpine.data('modalDialog', () => ({
        init() {
            this.opener = document.activeElement;
            this.$nextTick(() => (this.focusable()[0] ?? this.$el).focus());
        },

        destroy() {
            if (this.opener && document.contains(this.opener)) {
                this.opener.focus();
            }
        },

        focusable() {
            return [...this.$el.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')];
        },

        trap(event) {
            if (event.key !== 'Tab') {
                return;
            }

            const items = this.focusable();

            if (items.length === 0) {
                event.preventDefault();

                return;
            }

            const first = items[0];
            const last = items[items.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        },
    }));
});
