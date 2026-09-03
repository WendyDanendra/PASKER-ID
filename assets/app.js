function setActivePage(pageName) {
    document.querySelectorAll('.page').forEach((page) => {
        page.classList.toggle('active', page.dataset.page === pageName);
    });

    document.querySelectorAll('[data-page]').forEach((item) => {
        item.classList.toggle('active', item.dataset.page === pageName);
    });

    const currentCrumb = document.querySelector('[data-current-crumb]');
    const labelMap = {
        dashboard: 'Dasbor',
        lowongan: 'Lowongan',
        jadwal: 'Jadwal Wawancara',
        transfer: 'Transfer Pengelola',
        profil: 'Profil Pemberi Kerja',
        individual: 'Individual',
        employerdashboard: 'Pemberi Kerja Individu',
        seer: 'Pencari Kerja',
        seeker: 'Pencari Kerja',
        admin: 'Admin'
    };

    if (currentCrumb) {
        currentCrumb.textContent = labelMap[pageName] || 'Dasbor';
    }

    if (window.location.hash !== `#${pageName}`) {
        window.location.hash = pageName;
    }
}

function bindPageSwitchers() {
    document.querySelectorAll('[data-page]').forEach((item) => {
        item.addEventListener('click', () => {
            setActivePage(item.dataset.page);
        });
    });
}

function bindModal(openSelector, closeSelector, modalSelector) {
    const openButtons = document.querySelectorAll(openSelector);
    const closeButtons = document.querySelectorAll(closeSelector);
    const modal = document.querySelector(modalSelector);

    if (!modal || !openButtons.length) {
        return;
    }

    const open = () => {
        modal.classList.add('open');
        modal.dispatchEvent(new CustomEvent('modal:open'));
    };

    const close = () => {
        modal.classList.remove('open');
        modal.dispatchEvent(new CustomEvent('modal:close'));
    };

    openButtons.forEach((button) => {
        button.addEventListener('click', open);
    });

    closeButtons.forEach((button) => {
        button.addEventListener('click', close);
    });

    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            close();
        }
    });
}

function initRichEditors(root) {
    root.querySelectorAll('[data-rich-editor]').forEach((editor) => {
        if (editor.dataset.bound === 'true') {
            return;
        }
        editor.dataset.bound = 'true';

        const area = editor.querySelector('.rich-area');
        const input = editor.querySelector('textarea');
        if (!area || !input) {
            return;
        }

        const sync = () => {
            const text = (area.innerText || '').replace(/\u00a0/g, ' ').trim();
            input.value = text ? area.innerHTML.trim() : '';
        };

        editor.querySelectorAll('[data-cmd]').forEach((button) => {
            button.addEventListener('click', () => {
                area.focus();
                const command = button.dataset.cmd;
                if (command === 'createLink') {
                    const url = window.prompt('Masukkan tautan');
                    if (url) {
                        document.execCommand(command, false, url);
                    }
                } else {
                    document.execCommand(command, false, null);
                }
                sync();
            });
        });

        const blockSelect = editor.querySelector('[data-block]');
        if (blockSelect) {
            blockSelect.addEventListener('change', () => {
                area.focus();
                document.execCommand('formatBlock', false, blockSelect.value);
                sync();
            });
        }

        area.addEventListener('input', sync);
        area.addEventListener('blur', sync);
    });
}

function initChipField(root, key, options = {}) {
    const select = root.querySelector(`[data-chip-select="${key}"]`);
    const textInput = root.querySelector(`[data-chip-input="${key}"]`);
    const list = root.querySelector(`[data-chip-list="${key}"]`);
    const hidden = root.querySelector(`[data-chip-value="${key}"]`);
    if (!list || !hidden) {
        return;
    }

    const values = () => hidden.value.split(',').map((item) => item.trim()).filter(Boolean);

    const render = () => {
        list.replaceChildren();
        values().forEach((item) => {
            const chip = document.createElement('span');
            chip.className = 'choice-chip';
            chip.append(item);
            const button = document.createElement('button');
            button.type = 'button';
            button.dataset.removeChip = item;
            button.setAttribute('aria-label', 'Hapus');
            button.innerHTML = '&times;';
            chip.append(button);
            list.append(chip);
        });
    };
    hidden.refreshChips = render;

    const setValues = (next) => {
        hidden.value = [...new Set(next.map((item) => item.trim()).filter(Boolean))].join(',');
        hidden.setCustomValidity(hidden.value ? '' : (hidden.required ? 'Wajib diisi' : ''));
        render();
    };

    const addValue = (value) => {
        if (!value) {
            return;
        }
        setValues([...values(), value]);
    };

    if (select) {
        select.addEventListener('change', () => {
            addValue(select.value);
            select.value = '';
        });
    }

    if (textInput) {
        const commit = () => {
            addValue(textInput.value);
            textInput.value = '';
        };
        textInput.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                commit();
            }
        });
        textInput.addEventListener('blur', commit);
    }

    list.addEventListener('click', (event) => {
        const button = event.target.closest('[data-remove-chip]');
        if (!button) {
            return;
        }
        setValues(values().filter((item) => item !== button.dataset.removeChip));
    });

    if (options.initial && !hidden.value) {
        hidden.value = options.initial;
    }
    render();
}

function initJobCreateWizard() {
    const modal = document.querySelector('[data-modal="job-create"]');
    if (!modal) {
        return;
    }

    const form = modal.querySelector('[data-job-create-form]');
    const steps = [...modal.querySelectorAll('[data-job-step]')];
    const labels = [...modal.querySelectorAll('[data-step-label]')];
    const lines = [...modal.querySelectorAll('[data-step-line]')];
    const btnCancel = modal.querySelector('[data-job-cancel]');
    const btnBack = modal.querySelector('[data-job-back]');
    const btnNext = modal.querySelector('[data-job-next]');
    const btnSubmit = modal.querySelector('[data-job-submit]');
    let current = 1;

    initRichEditors(modal);
    initChipField(modal, 'skills');
    initChipField(modal, 'contacts');

    const syncRichText = () => {
        modal.querySelectorAll('[data-rich-editor]').forEach((editor) => {
            const area = editor.querySelector('.rich-area');
            const input = editor.querySelector('textarea');
            if (area && input) {
                const text = (area.innerText || '').replace(/\u00a0/g, ' ').trim();
                input.value = text ? area.innerHTML.trim() : '';
            }
        });
    };

    const setStep = (next) => {
        current = next;
        steps.forEach((step) => {
            step.hidden = Number(step.dataset.jobStep) !== current;
        });
        labels.forEach((label) => {
            const index = Number(label.dataset.stepLabel);
            label.classList.toggle('active', index === current);
            label.classList.toggle('done', index < current);
            const bubble = label.querySelector('.bubble');
            if (bubble) {
                bubble.innerHTML = index < current ? '<i class="fa-solid fa-check"></i>' : String(index);
            }
        });
        lines.forEach((line) => {
            line.classList.toggle('done', Number(line.dataset.stepLine) < current);
        });
        if (btnCancel) {
            btnCancel.hidden = current !== 1;
        }
        if (btnBack) {
            btnBack.hidden = current === 1;
        }
        if (btnNext) {
            btnNext.hidden = current === 3;
        }
        if (btnSubmit) {
            btnSubmit.hidden = current !== 3;
        }
        modal.querySelector('.modal-body')?.scrollTo({ top: 0, behavior: 'smooth' });
    };

    const validateStep = (stepNumber) => {
        syncRichText();
        const panel = modal.querySelector(`[data-job-step="${stepNumber}"]`);
        if (!panel) {
            return true;
        }

        const fields = panel.querySelectorAll('input, select, textarea');
        for (const field of fields) {
            if (field.disabled || field.type === 'checkbox') {
                continue;
            }
            field.setCustomValidity('');
            if (!field.checkValidity()) {
                field.reportValidity();
                return false;
            }
        }

        for (const group of panel.querySelectorAll('[data-required-group]')) {
            if (!group.querySelector('input:checked')) {
                const first = group.querySelector('input');
                first.setCustomValidity(group.dataset.requiredGroup || 'Pilih minimal satu opsi.');
                first.reportValidity();
                first.setCustomValidity('');
                return false;
            }
        }

        const salaryMin = panel.querySelector('[name="salary_min"]');
        const salaryMax = panel.querySelector('[name="salary_max"]');
        if (salaryMin && salaryMax && salaryMin.value && salaryMax.value && Number(salaryMax.value) < Number(salaryMin.value)) {
            salaryMax.setCustomValidity('Gaji maksimal harus lebih besar atau sama dengan gaji minimal.');
            salaryMax.reportValidity();
            salaryMax.setCustomValidity('');
            return false;
        }

        const ageMin = panel.querySelector('[name="age_min"]');
        const ageMax = panel.querySelector('[name="age_max"]');
        if (ageMin && ageMax && ageMin.value && ageMax.value && Number(ageMax.value) < Number(ageMin.value)) {
            ageMax.setCustomValidity('Usia maksimal harus lebih besar atau sama dengan usia minimal.');
            ageMax.reportValidity();
            ageMax.setCustomValidity('');
            return false;
        }

        return true;
    };

    btnNext?.addEventListener('click', () => {
        if (validateStep(current)) {
            setStep(current + 1);
        }
    });

    btnBack?.addEventListener('click', () => {
        setStep(Math.max(1, current - 1));
    });

    form?.addEventListener('submit', (event) => {
        syncRichText();
        if (!validateStep(1)) {
            event.preventDefault();
            setStep(1);
            return;
        }
        if (!validateStep(2)) {
            event.preventDefault();
            setStep(2);
            return;
        }
        if (!validateStep(3)) {
            event.preventDefault();
            setStep(3);
        }
    });

    modal.addEventListener('modal:open', () => {
        form?.reset();
        modal.querySelectorAll('.rich-area').forEach((area) => {
            area.innerHTML = '';
        });
        syncRichText();
        modal.querySelectorAll('[data-chip-value]').forEach((field) => {
            if (typeof field.refreshChips === 'function') {
                field.refreshChips();
            }
        });
        setStep(1);
    });

    setStep(1);
}

function initHashRouting(defaultPage) {
    const initialPage = (window.location.hash || `#${defaultPage}`).slice(1);
    setActivePage(initialPage || defaultPage);

    window.addEventListener('hashchange', () => {
        const nextPage = (window.location.hash || `#${defaultPage}`).slice(1);
        setActivePage(nextPage || defaultPage);
    });
}

function initSidebarToggle() {
    const sidebar = document.querySelector('.sidebar');
    const toggle = document.getElementById('sidebarToggle');
    if (!sidebar || !toggle || toggle.dataset.bound === 'true') {
        return;
    }
    toggle.dataset.bound = 'true';
    toggle.addEventListener('click', () => {
        sidebar.classList.toggle('collapsed');
    });
}

function initNotifications() {
    const wrap = document.querySelector('.notif-wrap');
    if (!wrap) {
        return;
    }
    const button = wrap.querySelector('[data-notif-toggle]');
    const panel = wrap.querySelector('.notif-panel');
    if (!button || !panel) {
        return;
    }
    button.addEventListener('click', (event) => {
        event.stopPropagation();
        panel.hidden = !panel.hidden;
        if (!panel.hidden) {
            fetch('notif-read.php').catch(() => {});
            button.classList.remove('has-unread');
        }
    });
    document.addEventListener('click', () => {
        panel.hidden = true;
    });
}

document.addEventListener('DOMContentLoaded', () => {
    if (document.body.dataset.useAppJs === 'true') {
        bindPageSwitchers();
    }

    bindModal('[data-open-modal="job-create"]', '[data-close-modal="job-create"]', '[data-modal="job-create"]');
    bindModal('[data-open-modal="job-create-mobile"]', '[data-close-modal="job-create"]', '[data-modal="job-create"]');
    initJobCreateWizard();
    initSidebarToggle();
    initNotifications();
    document.querySelectorAll('[data-revise-job]').forEach((button) => {
        button.addEventListener('click', () => {
            const hidden = document.getElementById('reviseJobId');
            if (hidden) {
                hidden.value = button.dataset.reviseJob;
            }
            const modal = document.querySelector('[data-modal="job-create"]');
            if (modal) {
                modal.classList.add('open');
                modal.dispatchEvent(new CustomEvent('modal:open'));
            }
        });
    });

    if (document.body.dataset.useAppJs === 'true' && document.body.dataset.defaultPage) {
        initHashRouting(document.body.dataset.defaultPage);
    }
});
