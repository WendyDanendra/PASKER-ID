// Theme Management (Terang, Gelap, Sistem)
function setTheme(theme) {
    localStorage.setItem('karirhub_theme', theme);
    if (theme === 'system') {
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        document.documentElement.classList.toggle('dark-theme', prefersDark);
    } else {
        document.documentElement.classList.toggle('dark-theme', theme === 'dark');
    }

    document.querySelectorAll('[data-theme-option]').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.themeOption === theme);
    });
}

function initTheme() {
    const savedTheme = localStorage.getItem('karirhub_theme') || 'light';
    setTheme(savedTheme);

    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
        if (localStorage.getItem('karirhub_theme') === 'system') {
            document.documentElement.classList.toggle('dark-theme', e.matches);
        }
    });
}

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
        profil: 'Profil Pemberi Kerja',
        individual: 'Individual',
        employerdashboard: 'Pemberi Kerja Individu',
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
        item.addEventListener('click', (e) => {
            if (item.tagName === 'A' && item.getAttribute('href') && !item.getAttribute('href').startsWith('#')) {
                return; // Let normal links work
            }
            e.preventDefault();
            setActivePage(item.dataset.page);
        });
    });
}

function bindModalsAndDrawers() {
    document.querySelectorAll('[data-open-modal]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const modalId = btn.dataset.openModal;
            const modal = document.querySelector(`[data-modal="${modalId}"]`);
            if (modal) modal.classList.add('open');
        });
    });

    document.querySelectorAll('[data-close-modal]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const modalId = btn.dataset.closeModal;
            const modal = document.querySelector(`[data-modal="${modalId}"]`);
            if (modal) modal.classList.remove('open');
        });
    });

    document.querySelectorAll('[data-open-drawer]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const drawerId = btn.dataset.openDrawer;
            const drawer = document.querySelector(`[data-drawer="${drawerId}"]`);
            if (drawer) drawer.classList.add('open');
        });
    });

    document.querySelectorAll('[data-close-drawer]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const drawerId = btn.dataset.closeDrawer;
            const drawer = document.querySelector(`[data-drawer="${drawerId}"]`);
            if (drawer) drawer.classList.remove('open');
        });
    });

    document.querySelectorAll('.modal-backdrop, .drawer-backdrop').forEach(backdrop => {
        backdrop.addEventListener('click', (e) => {
            if (e.target === backdrop) {
                backdrop.classList.remove('open');
            }
        });
    });
}

// Helper: Smooth scroll and focus KBJI field
function scrollAndFocusKbji() {
    const popup = document.querySelector('[data-modal="kbji-duplicate-modal"]');
    if (popup) popup.classList.remove('open');

    const jobCreateModal = document.querySelector('[data-modal="job-create"]');
    if (jobCreateModal) jobCreateModal.classList.add('open');

    const kbjiSelect = document.querySelector('[name="kbji_code"]');
    if (kbjiSelect) {
        kbjiSelect.scrollIntoView({ behavior: 'smooth', block: 'center' });
        kbjiSelect.focus();
        kbjiSelect.style.borderColor = '#ef4444';
        kbjiSelect.style.boxShadow = '0 0 0 4px rgba(239, 68, 68, 0.2)';
        setTimeout(() => {
            kbjiSelect.style.borderColor = '';
            kbjiSelect.style.boxShadow = '';
        }, 4000);
    }
}

// Cascading District & Village Select Sample Generator
function bindCascadingLocation() {
    const provSelect = document.getElementById('selectProvince');
    const citySelect = document.getElementById('selectCity');
    const distSelect = document.getElementById('selectDistrict');
    const villSelect = document.getElementById('selectVillage');

    if (!provSelect || !citySelect || !distSelect || !villSelect) return;

    const districts = {
        'Kota Bekasi': ['Bekasi Selatan', 'Bekasi Timur', 'Bekasi Barat', 'Bekasi Utara', 'Pondok Gede', 'Jatiasih'],
        'Kabupaten Bekasi': ['Cikarang Pusat', 'Cikarang Selatan', 'Cikarang Barat', 'Cikarang Utara', 'Tambun Selatan'],
        'Kota Bandung': ['Coblong', 'Sukajadi', 'Cicendo', 'Sumur Bandung', 'Bandung Wetan'],
        'Jakarta Selatan': ['Kebayoran Baru', 'Cilandak', 'Pasar Minggu', 'Setiabudi', 'Tebet']
    };

    const villages = {
        'Bekasi Selatan': ['Pekayon Jaya', 'Jatibening', 'Jakasetia', 'Kayuringin Jaya', 'Marga Jaya'],
        'Bekasi Timur': ['Duren Jaya', 'Bekasi Jaya', 'Margahayu'],
        'Cikarang Pusat': ['Jayamukti', 'Pasirranji', 'Sukamahi'],
        'Coblong': ['Dago', 'Lebak Siliwangi', 'Sekeloa']
    };

    citySelect.addEventListener('change', () => {
        const val = citySelect.value;
        const list = districts[val] || ['Bekasi Selatan', 'Cikarang Pusat', 'Coblong', 'Kebayoran Baru'];
        distSelect.innerHTML = '<option value="">Pilih Kecamatan</option>' + list.map(d => `<option value="${d}">${d}</option>`).join('');
        villSelect.innerHTML = '<option value="">Pilih Kelurahan/Desa</option>';
    });

    distSelect.addEventListener('change', () => {
        const val = distSelect.value;
        const list = villages[val] || ['Pekayon Jaya', 'Jatibening', 'Jakasetia', 'Marga Jaya'];
        villSelect.innerHTML = '<option value="">Pilih Kelurahan/Desa</option>' + list.map(v => `<option value="${v}">${v}</option>`).join('');
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
    initTheme();
    bindPageSwitchers();
    bindModalsAndDrawers();
    bindCascadingLocation();

    const defaultPage = document.body.dataset.defaultPage || 'dashboard';
    initHashRouting(defaultPage);
});

