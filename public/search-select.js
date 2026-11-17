'use strict';

(() => {
    const instances = new WeakMap();
    let active = null;
    let sequence = 0;

    class SearchSelect {
        constructor(select) {
            this.select = select;
            this.query = '';
            this.highlight = -1;
            this.wrapper = document.createElement('div');
            this.wrapper.className = 'search-select';
            this.input = document.createElement('input');
            this.input.type = 'text';
            this.input.className = 'search-select-input';
            this.input.autocomplete = 'off';
            this.input.spellcheck = false;
            this.input.setAttribute('role', 'combobox');
            this.input.setAttribute('aria-autocomplete', 'list');
            this.input.setAttribute('aria-haspopup', 'listbox');
            this.input.setAttribute('aria-expanded', 'false');
            this.input.setAttribute('aria-required', String(select.required));
            this.input.placeholder = 'Ketik untuk mencari…';
            this.panel = document.createElement('div');
            this.panel.className = 'search-select-options';
            this.panel.id = `select-options-${++sequence}`;
            this.panel.setAttribute('role', 'listbox');
            this.panel.hidden = true;
            this.input.setAttribute('aria-controls', this.panel.id);
            Array.from(select.labels || []).forEach((label, i) => {
                if (!label.id) label.id = `select-label-${sequence}-${i}`;
                label.addEventListener('click', event => {
                    event.preventDefault();
                    if (!this.input.disabled) this.input.focus();
                });
            });
            const labelledBy = Array.from(select.labels || []).map(l => l.id).join(' ');
            if (labelledBy) this.input.setAttribute('aria-labelledby', labelledBy);
            else this.input.setAttribute('aria-label', select.getAttribute('aria-label') || 'Pilihan');
            this.panel.setAttribute('aria-label', 'Hasil pencarian pilihan');
            select.before(this.wrapper);
            this.wrapper.append(select, this.input, this.panel);
            select.hidden = true;
            select.tabIndex = -1;
            this.input.addEventListener('focus', () => { this.open(); this.input.select(); });
            this.input.addEventListener('click', () => { if (this.panel.hidden) { this.open(); this.input.select(); } });
            this.input.addEventListener('input', () => {
                this.query = this.input.value;
                this.highlight = -1;
                this.open(false);
            });
            this.input.addEventListener('keydown', event => this.keydown(event));
            this.input.addEventListener('blur', () => this.close());
            this.panel.addEventListener('pointerdown', event => event.preventDefault());
            this.panel.addEventListener('click', event => {
                const option = event.target.closest('[role=option]');
                if (option && option.getAttribute('aria-disabled') !== 'true') this.choose(Number(option.dataset.index));
            });
            select.addEventListener('change', () => this.refresh());
            select.addEventListener('invalid', event => {
                event.preventDefault();
                this.input.setAttribute('aria-invalid', 'true');
                this.input.focus();
            });
            this.observer = new MutationObserver(() => this.refresh());
            this.observer.observe(select, {childList:true, subtree:true, attributes:true, attributeFilter:['disabled','required','selected','label']});
            this.refresh();
        }

        refresh() {
            this.input.disabled = this.select.disabled;
            this.input.setAttribute('aria-required', String(this.select.required));
            if (this.select.value || !this.select.required) this.input.removeAttribute('aria-invalid');
            if (this.panel.hidden) this.input.value = this.select.selectedOptions[0]?.textContent || '';
            else if (this.select.disabled) this.close();
            else this.draw();
        }

        open(reset = true) {
            if (this.select.disabled) return;
            if (active && active !== this) active.close();
            active = this;
            if (reset) { this.query = ''; this.highlight = -1; }
            this.panel.hidden = false;
            this.wrapper.classList.add('is-open');
            this.input.setAttribute('aria-expanded', 'true');
            this.draw();
            const rect = this.input.getBoundingClientRect();
            const boundary = this.wrapper.closest('dialog')?.getBoundingClientRect();
            const bottom = Math.min(window.innerHeight, boundary?.bottom ?? window.innerHeight) - 16;
            const top = Math.max(0, boundary?.top ?? 0) + 16;
            const below = bottom - rect.bottom;
            const above = rect.top - top;
            const upwards = below < 180 && above > below;
            this.wrapper.classList.toggle('opens-up', upwards);
            this.panel.style.maxHeight = `${Math.max(72, Math.min(240, (upwards ? above : below) - 8))}px`;
        }

        draw() {
            const query = this.query.toLocaleLowerCase('id-ID').trim();
            this.filtered = Array.from(this.select.options).map((option, index) => ({option,index}))
                .filter(({option}) => !option.hidden && option.textContent.toLocaleLowerCase('id-ID').includes(query));
            this.panel.replaceChildren();
            if (!this.filtered.length) {
                const empty = document.createElement('div');
                empty.className = 'search-select-empty';
                empty.setAttribute('role', 'status');
                empty.textContent = 'Tidak ada pilihan yang cocok.';
                this.panel.append(empty);
                this.input.removeAttribute('aria-activedescendant');
                return;
            }
            const enabled = this.filtered.filter(({option}) => !option.disabled && !option.parentElement.disabled);
            if (!enabled.some(({index}) => index === this.highlight)) {
                this.highlight = enabled.find(({option}) => option.selected)?.index ?? enabled[0]?.index ?? -1;
            }
            this.filtered.forEach(({option,index}) => {
                const row = document.createElement('div');
                row.id = `${this.panel.id}-${index}`;
                row.dataset.index = index;
                row.setAttribute('role', 'option');
                row.setAttribute('aria-selected', String(option.selected));
                row.setAttribute('aria-disabled', String(option.disabled || !!option.parentElement.disabled));
                row.className = 'search-select-option';
                row.classList.toggle('is-highlighted', index === this.highlight);
                row.textContent = option.textContent;
                this.panel.append(row);
            });
            if (this.highlight >= 0) this.input.setAttribute('aria-activedescendant', `${this.panel.id}-${this.highlight}`);
        }

        choose(index) {
            const option = this.select.options[index];
            if (!option || option.disabled || option.parentElement.disabled) return;
            const changed = this.select.selectedIndex !== index;
            this.select.selectedIndex = index;
            this.input.removeAttribute('aria-invalid');
            this.close();
            if (changed) this.select.dispatchEvent(new Event('change', {bubbles:true}));
        }

        keydown(event) {
            if (event.key === 'Escape' && !this.panel.hidden) {
                event.preventDefault(); event.stopPropagation(); this.close(); return;
            }
            if (event.key === 'Tab') { this.close(); return; }
            if (!['ArrowDown','ArrowUp','Home','End','Enter'].includes(event.key)) return;
            if (['Home','End'].includes(event.key) && this.panel.hidden) return;
            event.preventDefault();
            if (this.panel.hidden) { this.open(); return; }
            if (event.key === 'Enter') { if (this.highlight >= 0) this.choose(this.highlight); return; }
            const enabled = this.filtered.filter(({option}) => !option.disabled && !option.parentElement.disabled);
            if (!enabled.length) return;
            const current = enabled.findIndex(({index}) => index === this.highlight);
            const position = event.key === 'Home' ? 0 : event.key === 'End' ? enabled.length - 1 : Math.max(0, Math.min(enabled.length - 1, current + (event.key === 'ArrowDown' ? 1 : -1)));
            this.highlight = enabled[position].index;
            this.draw();
            document.getElementById(`${this.panel.id}-${this.highlight}`)?.scrollIntoView({block:'nearest'});
        }

        close() {
            this.panel.hidden = true;
            this.wrapper.classList.remove('is-open','opens-up');
            this.input.setAttribute('aria-expanded', 'false');
            this.input.removeAttribute('aria-activedescendant');
            this.input.value = this.select.selectedOptions[0]?.textContent || '';
            this.query = '';
            if (active === this) active = null;
        }
    }

    document.addEventListener('pointerdown', event => {
        if (active && !active.wrapper.contains(event.target)) active.close();
    });
    window.SearchSelect = {
        enhance(root = document) {
            root.querySelectorAll('select').forEach(select => {
                if (instances.has(select)) instances.get(select).refresh();
                else instances.set(select, new SearchSelect(select));
            });
        },
        close() { active?.close(); },
        isOpen() { return !!active; },
    };
})();
