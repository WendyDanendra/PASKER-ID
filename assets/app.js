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
    const titleEl = document.getElementById('jobCreateTitle');
    const subtitleEl = document.getElementById('jobCreateSubtitle');
    const banner = document.getElementById('revisionBanner');
    const bannerText = document.getElementById('revisionBannerText');
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

    const setJobCreateMode = (mode, adminNotes = '') => {
        modal.dataset.jobFormMode = mode;
        if (mode === 'revise') {
            if (titleEl) {
                titleEl.textContent = 'Revisi Lowongan';
            }
            if (subtitleEl) {
                subtitleEl.textContent = 'Data sebelumnya sudah terisi. Perbarui bagian yang diminta admin, lalu kirim ulang.';
            }
            if (btnSubmit) {
                btnSubmit.textContent = 'Kirim Ulang Revisi';
            }
            if (banner && bannerText) {
                const notes = String(adminNotes || '').trim();
                banner.hidden = notes === '';
                bannerText.textContent = notes;
            }
            return;
        }

        if (titleEl) {
            titleEl.textContent = 'Tambah Lowongan';
        }
        if (subtitleEl) {
            subtitleEl.textContent = 'Lengkapi form berikut untuk mengisi lowongan';
        }
        if (btnSubmit) {
            btnSubmit.textContent = 'Tambah Loker';
        }
        if (banner) {
            banner.hidden = true;
        }
        if (bannerText) {
            bannerText.textContent = '';
        }
    };

    const setFieldValue = (name, value) => {
        const field = form?.querySelector(`[name="${name}"]`);
        if (!field) {
            return;
        }
        field.value = value == null ? '' : String(value);
    };

    const setCheckboxGroup = (name, selected) => {
        const values = new Set((selected || []).map((item) => String(item)));
        form?.querySelectorAll(`input[name="${name}"]`).forEach((input) => {
            input.checked = values.has(input.value);
        });
    };

    const setCheckbox = (name, checked) => {
        const input = form?.querySelector(`input[name="${name}"]`);
        if (input) {
            input.checked = Boolean(checked);
        }
    };

    const setRichField = (name, html) => {
        const input = form?.querySelector(`textarea[name="${name}"]`);
        if (!input) {
            return;
        }
        input.value = html || '';
        const area = input.closest('[data-rich-editor]')?.querySelector('.rich-area');
        if (area) {
            area.innerHTML = html || '';
        }
    };

    const setChips = (key, items) => {
        const hidden = form?.querySelector(`[data-chip-value="${key}"]`);
        if (!hidden) {
            return;
        }
        hidden.value = (items || []).map((item) => String(item).trim()).filter(Boolean).join(',');
        if (typeof hidden.refreshChips === 'function') {
            hidden.refreshChips();
        }
    };

    const fillJobCreateForm = (data) => {
        if (!form || !data) {
            return;
        }

        setFieldValue('job_title', data.title);
        setRichField('job_description', data.description);
        setFieldValue('kbji_code', data.kbji_code);
        setFieldValue('job_location', data.location);
        setFieldValue('job_type', data.job_type);
        setFieldValue('job_field', data.job_field);
        setFieldValue('industry', data.industry);
        setCheckboxGroup('physical_condition[]', data.physical_conditions);
        setCheckboxGroup('gender[]', data.genders);
        setFieldValue('disability_excluded', data.disability_excluded);
        setFieldValue('salary_min', data.salary_min);
        setFieldValue('salary_max', data.salary_max);
        setCheckbox('show_salary', data.show_salary);
        setCheckbox('is_remote', data.is_remote);
        setCheckbox('is_limited', data.is_limited);
        setFieldValue('expiry_days', data.expiry_days);
        setFieldValue('quota', data.quota || 1);
        setFieldValue('education_required', data.education_required);
        setFieldValue('experience_required', data.experience_required);
        setCheckboxGroup('marital_status[]', data.marital_statuses);
        setFieldValue('age_min', data.age_min);
        setFieldValue('age_max', data.age_max);
        setRichField('special_requirements', data.special_requirements);
        setChips('skills', data.skills);
        if (Array.isArray(data.contacts) && data.contacts.length) {
            setChips('contacts', data.contacts);
        }
        syncRichText();
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
        if (modal.dataset.skipReset === 'true') {
            modal.dataset.skipReset = 'false';
            setStep(1);
            return;
        }

        modal.dataset.jobFormMode = 'create';
        form?.reset();
        const reviseId = document.getElementById('reviseJobId');
        if (reviseId) {
            reviseId.value = '';
        }
        modal.querySelectorAll('.rich-area').forEach((area) => {
            area.innerHTML = '';
        });
        syncRichText();
        modal.querySelectorAll('[data-chip-value]').forEach((field) => {
            if (typeof field.refreshChips === 'function') {
                field.refreshChips();
            }
        });
        setJobCreateMode('create');
        setStep(1);
    });

    document.querySelectorAll('[data-revise-job]').forEach((button) => {
        button.addEventListener('click', () => {
            const jobId = button.dataset.reviseJob;
            const hidden = document.getElementById('reviseJobId');
            fetch(`dashboard.php?job_json=${encodeURIComponent(jobId)}`)
                .then((response) => {
                    if (!response.ok) {
                        throw new Error('not found');
                    }
                    return response.json();
                })
                .then((payload) => {
                    if (!payload?.ok || !payload.data) {
                        throw new Error('invalid');
                    }
                    modal.dataset.jobFormMode = 'revise';
                    modal.dataset.skipReset = 'true';
                    if (hidden) {
                        hidden.value = String(payload.data.id || jobId);
                    }
                    fillJobCreateForm(payload.data);
                    setJobCreateMode('revise', payload.data.admin_notes);
                    modal.classList.add('open');
                    modal.dispatchEvent(new CustomEvent('modal:open'));
                })
                .catch(() => {
                    window.alert('Data lowongan tidak dapat dimuat. Silakan coba lagi.');
                });
        });
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

function initAdminJobReview() {
    const forms = document.querySelectorAll('[data-review-form]');
    if (!forms.length) {
        return;
    }

    forms.forEach((form) => {
        const notes = form.querySelector('[name="admin_notes"]');
        const reasonSelect = form.querySelector('[data-note-reason]');
        const defaultApprove = form.dataset.defaultApprove || 'Lowongan telah memenuhi syarat dan disetujui untuk ditayangkan';

        const applyReason = (reason) => {
            if (!notes || !reason) {
                return;
            }
            notes.value = reason;
            notes.dispatchEvent(new Event('input', { bubbles: true }));
        };

        reasonSelect?.addEventListener('change', () => {
            applyReason(reasonSelect.value.trim());
        });

        form.querySelectorAll('button[name="decision"]').forEach((button) => {
            button.addEventListener('click', () => {
                if (!notes) {
                    return;
                }
                if (button.value === 'approve') {
                    notes.value = defaultApprove;
                    return;
                }

                const selectedReason = (reasonSelect?.value || '').trim();
                if (selectedReason) {
                    applyReason(selectedReason);
                } else if (notes.value.trim() === defaultApprove) {
                    notes.value = '';
                }
            });
        });
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
    initAdminJobReview();
    initSidebarToggle();
    initNotifications();
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
    }[char]));

    function fillList(target, items, mapper) {
        if (!target) {
            return;
        }
        if (!items || !items.length) {
            target.innerHTML = '<div class="tiny">Belum ada data.</div>';
            return;
        }
        target.innerHTML = items.map(mapper).join('');
    }

    function openApplicantProfile(applicationId) {
        const modal = document.querySelector('[data-modal="applicant-profile"]');
        if (!modal) {
            return;
        }
        fetch('dashboard.php?applicant_json=' + encodeURIComponent(applicationId))
            .then((response) => response.json())
            .then((payload) => {
                if (!payload.ok) {
                    return;
                }
                const data = payload.data;
                document.getElementById('applicantId').value = data.id;
                document.getElementById('applicantName').textContent = data.seeker_name || 'Profil Pelamar';
                document.getElementById('applicantJob').textContent = data.job_title || '';
                document.getElementById('applicantStatus').value = data.status || 'Lamaran Masuk';
                const rows = [
                    ['Email', data.seeker_email],
                    ['Telepon', data.phone],
                    ['NIK', data.nik],
                    ['Jenis kelamin', data.gender],
                    ['Status pernikahan', data.marital_status],
                    ['Tempat, tanggal lahir', [data.birth_place, data.birth_date].filter(Boolean).join(', ')],
                    ['Alamat KTP', data.ktp_address],
                    ['Alamat domisili', data.domicile_address],
                ];
                document.getElementById('applicantBiodata').innerHTML = rows.map(([label, value]) => (
                    `<div class="summary-row"><span>${escapeHtml(label)}</span><strong>${escapeHtml(value || '-')}</strong></div>`
                )).join('');
                fillList(document.getElementById('applicantEducation'), data.profile.education, (item) => (
                    `<div class="record-item"><strong>${escapeHtml(item.level || '')} ${escapeHtml(item.school_name || '')}</strong><span>${escapeHtml(item.major || '')} ${escapeHtml(item.graduation_year || '')}</span></div>`
                ));
                fillList(document.getElementById('applicantExperience'), data.profile.experience, (item) => (
                    `<div class="record-item"><strong>${escapeHtml(item.company_name || '')} - ${escapeHtml(item.position || '')}</strong><span>${escapeHtml(item.duration || '')} ${escapeHtml(item.notes || '')}</span></div>`
                ));
                fillList(document.getElementById('applicantSkills'), data.profile.skills, (item) => (
                    `<div class="record-item"><strong>${escapeHtml(item.skill_name || '')}</strong><span>${escapeHtml(item.level || '')}</span></div>`
                ));
                modal.classList.add('open');
                modal.onclick = (event) => {
                    if (event.target === modal) {
                        modal.classList.remove('open');
                    }
                };
            })
            .catch(() => {});
    }

    document.querySelectorAll('[data-open-applicant]').forEach((trigger) => {
        trigger.addEventListener('click', () => {
            openApplicantProfile(trigger.dataset.openApplicant);
        });
    });

    document.querySelectorAll('[data-close-modal="applicant-profile"]').forEach((button) => {
        button.addEventListener('click', () => {
            document.querySelector('[data-modal="applicant-profile"]')?.classList.remove('open');
        });
    });

    const expandApplicants = document.querySelector('[data-expand-applicants]');
    if (expandApplicants) {
        expandApplicants.addEventListener('click', () => {
            document.querySelectorAll('.applicant-ready-list [hidden]').forEach((item) => {
                item.hidden = false;
            });
            expandApplicants.remove();
        });
    }

    if (document.body.dataset.useAppJs === 'true' && document.body.dataset.defaultPage) {
        initHashRouting(document.body.dataset.defaultPage);
    }
});
