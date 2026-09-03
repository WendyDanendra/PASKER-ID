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
    const openButton = document.querySelector(openSelector);
    const closeButton = document.querySelector(closeSelector);
    const modal = document.querySelector(modalSelector);

    if (!openButton || !closeButton || !modal) {
        return;
    }

    openButton.addEventListener('click', () => {
        modal.classList.add('open');
    });

    closeButton.addEventListener('click', () => {
        modal.classList.remove('open');
    });

    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            modal.classList.remove('open');
        }
    });
}

function initHashRouting(defaultPage) {
    const initialPage = (window.location.hash || `#${defaultPage}`).slice(1);
    setActivePage(initialPage || defaultPage);

    window.addEventListener('hashchange', () => {
        const nextPage = (window.location.hash || `#${defaultPage}`).slice(1);
        setActivePage(nextPage || defaultPage);
    });
}

document.addEventListener('DOMContentLoaded', () => {
    if (document.body.dataset.useAppJs === 'true') {
        bindPageSwitchers();
    }

    bindModal('[data-open-modal="job-create"]', '[data-close-modal="job-create"]', '[data-modal="job-create"]');
    bindModal('[data-open-modal="job-create-mobile"]', '[data-close-modal="job-create"]', '[data-modal="job-create"]');

    if (document.body.dataset.useAppJs === 'true' && document.body.dataset.defaultPage) {
        initHashRouting(document.body.dataset.defaultPage);
    }
});
