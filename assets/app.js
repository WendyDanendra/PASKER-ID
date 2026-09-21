// Theme Management (Terang, Gelap, Sistem)
function setTheme(theme) {
    localStorage.setItem('karirhub_theme', theme);
    let isDark = false;
    if (theme === 'system') {
        isDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    } else {
        isDark = (theme === 'dark');
    }
    document.documentElement.classList.toggle('dark-theme', isDark);
    if (document.body) {
        document.body.classList.toggle('dark-theme', isDark);
    }

    const toggleBtn = document.getElementById('themeToggleBtn');
    if (toggleBtn) {
        toggleBtn.innerHTML = isDark ? '<i class="fa-solid fa-moon" style="color:#38bdf8;"></i>' : '<i class="fa-solid fa-display"></i>';
        toggleBtn.title = isDark ? 'Tema: Gelap (Klik untuk beralih ke Terang)' : 'Tema: Terang (Klik untuk beralih ke Gelap)';
    }

    document.querySelectorAll('[data-theme-option]').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.themeOption === theme);
    });

    document.querySelectorAll('[data-theme-val]').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.themeVal === theme);
    });
}

function initTheme() {
    const savedTheme = localStorage.getItem('karirhub_theme') || 'light';
    setTheme(savedTheme);

    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
        if (localStorage.getItem('karirhub_theme') === 'system') {
            const prefersDark = e.matches;
            document.documentElement.classList.toggle('dark-theme', prefersDark);
            if (document.body) document.body.classList.toggle('dark-theme', prefersDark);
            const toggleBtn = document.getElementById('themeToggleBtn');
            if (toggleBtn) {
                toggleBtn.innerHTML = prefersDark ? '<i class="fa-solid fa-moon" style="color:#38bdf8;"></i>' : '<i class="fa-solid fa-display"></i>';
            }
        }
    });
}

function setActivePage(pageName) {
    const dataPages = document.querySelectorAll('.page[data-page], .page[id^="page-"]');
    if (dataPages.length === 0) {
        return;
    }

    const targetPage = pageName || 'dashboard';

    dataPages.forEach((page) => {
        const matches = page.dataset.page === targetPage || page.id === `page-${targetPage}`;
        page.classList.toggle('active', matches);
    });

    document.querySelectorAll('[data-page]:not(.page):not(section), [data-nav]').forEach((item) => {
        const p = item.dataset.page || item.dataset.nav;
        item.classList.toggle('active', p === targetPage);
    });

    const navDrawer = document.getElementById('navDrawer');
    const drawerBackdrop = document.getElementById('drawerBackdrop');
    if (navDrawer) navDrawer.classList.remove('open');
    if (drawerBackdrop) drawerBackdrop.classList.remove('open');

    const currentCrumb = document.querySelector('[data-current-crumb]') || document.getElementById('crumbCurrent');
    const labelMap = {
        dashboard: 'Dasbor',
        lowongan: 'Lowongan',
        jadwal: 'Jadwal Wawancara',
        profil: 'Profil Pemberi kerja',
        jobs: 'Lowongan Kerja',
        profile: 'Profil Pencari Kerja',
        directory_individual: 'Direktori Profil',
        verifikasi_employer: 'Verifikasi Profil',
        verifikasi_job: 'Verifikasi Lowongan'
    };

    if (currentCrumb) {
        currentCrumb.textContent = labelMap[targetPage] || 'Dasbor';
    }

    if (window.location.hash !== `#${targetPage}`) {
        window.location.hash = targetPage;
    }
}
window.setActivePage = setActivePage;
window.showPage = setActivePage;

function bindPageSwitchers() {
    document.querySelectorAll('[data-page], [data-nav]').forEach((item) => {
        if (item.classList.contains('page') || item.tagName === 'SECTION') return;
        item.addEventListener('click', (e) => {
            if (item.tagName === 'A' && item.getAttribute('href') && !item.getAttribute('href').startsWith('#')) {
                return; // Let normal links work
            }
            const target = item.dataset.page || item.dataset.nav;
            if (target) {
                e.preventDefault();
                e.stopPropagation();
                if (typeof showPage === 'function') {
                    showPage(target);
                } else {
                    setActivePage(target);
                }
            }
        });
    });
}

function bindModalsAndDrawers() {
    document.querySelectorAll('[data-open-modal]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const modalId = btn.dataset.openModal;
            const modal = document.querySelector(`[data-modal="${modalId}"]`);
            if (modal) {
                modal.classList.add('open');
                modal.dispatchEvent(new CustomEvent('modal:open'));
                if (modalId === 'modal-employer-profile' && typeof invalidatePkiMap === 'function') {
                    invalidatePkiMap();
                }
            }
        });
    });

    document.querySelectorAll('[data-close-modal]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const modalId = btn.dataset.closeModal;
            const modal = document.querySelector(`[data-modal="${modalId}"]`);
            if (modal) {
                modal.classList.remove('open');
                modal.dispatchEvent(new CustomEvent('modal:close'));
            }
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

// Comprehensive Cascading Locations Dictionary for Indonesia
const ID_LOCATIONS = {
    'DKI Jakarta': {
        'Jakarta Pusat': {
            'Gambir': { 'Gambir': '10110', 'Kebon Kelapa': '10120', 'Petojo Selatan': '10160', 'Duri Pulo': '10140' },
            'Tanah Abang': { 'Bendungan Hilir': '10210', 'Karet Tengsin': '10220', 'Kebon Melati': '10230', 'Kebon Kacang': '10240', 'Kampung Bali': '10250', 'Petamburan': '10260', 'Gelora': '10270' },
            'Menteng': { 'Menteng': '10310', 'Pegangsaan': '10320', 'Cikini': '10330', 'Gondangdia': '10350', 'Kebon Sirih': '10340' },
            'Senen': { 'Senen': '10410', 'Kenari': '10430', 'Paseban': '10440', 'Kramat': '10450', 'Kwitang': '10420', 'Bungur': '10460' }
        },
        'Jakarta Selatan': {
            'Kebayoran Baru': { 'Selong': '12110', 'Gunung': '12120', 'Kramat Pela': '12130', 'Gandaria Utara': '12140', 'Cipete Utara': '12150', 'Melawai': '12160', 'Pulo': '12160', 'Petogogan': '12170', 'Rawa Barat': '12180', 'Senayan': '12190' },
            'Cilandak': { 'Cilandak Barat': '12430', 'Lebak Bulus': '12440', 'Pondok Labu': '12450', 'Gandaria Selatan': '12420', 'Cipete Selatan': '12410' },
            'Setiabudi': { 'Setiabudi': '12910', 'Karet': '12920', 'Karet Semanggi': '12930', 'Karet Kuningan': '12940', 'Kuningan Timur': '12950', 'Menteng Atas': '12960', 'Pasar Manggis': '12970', 'Guntur': '12980' },
            'Pasar Minggu': { 'Pejaten Barat': '12510', 'Pejaten Timur': '12510', 'Pasar Minggu': '12520', 'Kebagusan': '12520', 'Jati Padang': '12540', 'Ragunan': '12550', 'Cilandak Timur': '12560' },
            'Tebet': { 'Tebet Barat': '12810', 'Tebet Timur': '12820', 'Kebon Baru': '12830', 'Bukit Duri': '12840', 'Manggarai': '12850', 'Manggarai Selatan': '12860', 'Menteng Dalam': '12870' }
        },
        'Jakarta Barat': {
            'Grogol Petamburan': { 'Tanjung Duren Utara': '11470', 'Tanjung Duren Selatan': '11470', 'Tomang': '11440', 'Grogol': '11450', 'Jelambar': '11460', 'Wijaya Kusuma': '11460' },
            'Kebon Jeruk': { 'Kebon Jeruk': '11530', 'Sukabumi Utara': '11540', 'Sukabumi Selatan': '11560', 'Kelapa Dua': '11550', 'Duri Kepa': '11510', 'Kedoya Selatan': '11520', 'Kedoya Utara': '11520' },
            'Kembangan': { 'Kembangan Utara': '11610', 'Kembangan Selatan': '11610', 'Meruya Utara': '11620', 'Meruya Selatan': '11650', 'Srengseng': '11630', 'Joglo': '11640' }
        },
        'Jakarta Timur': {
            'Jatinegara': { 'Bali Mester': '13310', 'Kampung Melayu': '13320', 'Bidara Cina': '13330', 'Cipinang Cempedak': '13340', 'Rawa Bunga': '13350', 'Cipinang Besar Utara': '13410', 'Cipinang Besar Selatan': '13410', 'Cipinang Muara': '13420' },
            'Duren Sawit': { 'Pondok Bambu': '13430', 'Duren Sawit': '13440', 'Pondok Kelapa': '13450', 'Malaka Jaya': '13460', 'Malaka Sari': '13460', 'Pondok Kopi': '13460', 'Klender': '13470' },
            'Matraman': { 'Pisangan Baru': '13110', 'Utan Kayu Selatan': '13120', 'Utan Kayu Utara': '13120', 'Kayu Manis': '13130', 'Pal Matraman': '13140', 'Kebon Manggis': '13150' }
        },
        'Jakarta Utara': {
            'Penjaringan': { 'Pluit': '14450', 'Pejagalan': '14450', 'Kapuk Muara': '14460', 'Kamal Muara': '14470', 'Penjaringan': '14440' },
            'Kelapa Gading': { 'Kelapa Gading Barat': '14240', 'Kelapa Gading Timur': '14240', 'Pegangsaan Dua': '14250' }
        }
    },
    'Jawa Barat': {
        'Kota Bekasi': {
            'Bekasi Selatan': { 'Pekayon Jaya': '17148', 'Jaka Setia': '17147', 'Kayuringin Jaya': '17144', 'Marga Jaya': '17141' },
            'Bekasi Timur': { 'Aren Jaya': '17111', 'Bekasi Jaya': '17112', 'Duren Jaya': '17111', 'Margahayu': '17113' },
            'Bekasi Barat': { 'Bintara': '17134', 'Kranji': '17135', 'Kota Baru': '17133', 'Jaka Sampurna': '17145' },
            'Bekasi Utara': { 'Harapan Baru': '17123', 'Harapan Jaya': '17124', 'Kaliabang Tengah': '17125', 'Perwira': '17122', 'Teluk Pucung': '17121' },
            'Pondok Gede': { 'Jatibening': '17412', 'Jatibening Baru': '17412', 'Jaticempaka': '17411', 'Jatimakmur': '17413', 'Jatiwaringin': '17411' },
            'Jatiasih': { 'Jatiasih': '17423', 'Jatikramat': '17421', 'Jatimekar': '17422', 'Jatirasa': '17424', 'Jatisari': '17426', 'Jatiluhur': '17425' },
            'Medan Satria': { 'Medan Satria': '17132', 'Pejuang': '17131', 'Harapan Mulya': '17143', 'Kali Baru': '17133' },
            'Rawalumbu': { 'Bojong Rawalumbu': '17116', 'Bojong Menteng': '17117', 'Pengasinan': '17115', 'Sepanjang Jaya': '17114' }
        },
        'Kabupaten Bekasi': {
            'Cikarang Pusat': { 'Jayamukti': '17530', 'Pasirranji': '17530', 'Pasirtanjung': '17530', 'Sukamahi': '17530', 'Cicau': '17530', 'Hegarmanah': '17530' },
            'Cikarang Selatan': { 'Ciantra': '17530', 'Cibatu': '17530', 'Pasirsari': '17530', 'Serang': '17530', 'Sukadami': '17530', 'Sukaresmi': '17530', 'Sukasejati': '17530' },
            'Cikarang Barat': { 'Danau Indah': '17520', 'Gandamekar': '17520', 'Gandasari': '17520', 'Jatiwangi': '17520', 'Kalijaya': '17520', 'Telagamurni': '17520', 'Telajung': '17520' },
            'Cikarang Utara': { 'Cikarang Kota': '17530', 'Harjamekar': '17530', 'Karangasih': '17530', 'Karangbaru': '17530', 'Pasirgombong': '17530', 'Simpangan': '17530', 'Tanjungsari': '17530' },
            'Tambun Selatan': { 'Jatimulya': '17510', 'Lambangjaya': '17510', 'Lambangsari': '17510', 'Mangunjaya': '17510', 'Setiadarma': '17510', 'Setiamekar': '17510', 'Sumberjaya': '17510', 'Tambun': '17510', 'Tridaya Sakti': '17510' }
        },
        'Kota Bandung': {
            'Coblong': { 'Dago': '40135', 'Lebak Gede': '40132', 'Lebak Siliwangi': '40132', 'Sadang Serang': '40133', 'Sekeloa': '40134' },
            'Sukajadi': { 'Cipedes': '40162', 'Pasteur': '40161', 'Sukabungah': '40162', 'Sukagalih': '40163', 'Sukawarna': '40164' },
            'Cicendo': { 'Arjuna': '40172', 'Husen Sastranegara': '40174', 'Pajajaran': '40173', 'Pamoyanan': '40173', 'Pasirkaliki': '40171', 'Sukaraja': '40175' },
            'Sumur Bandung': { 'Braga': '40111', 'Kebon Pisang': '40112', 'Merdeka': '40113', 'Babakan Ciamis': '40117' },
            'Bandung Wetan': { 'Cihapit': '40114', 'Citarum': '40115', 'Tamansari': '40116' }
        },
        'Kota Bogor': {
            'Bogor Tengah': { 'Babakan': '16128', 'Babakan Pasar': '16126', 'Cibogor': '16124', 'Ciwaringin': '16124', 'Gudang': '16123', 'Kebon Kelapa': '16125', 'Pabaton': '16121', 'Paledang': '16122', 'Panaragan': '16125', 'Sempur': '16129', 'Tegallega': '16127' },
            'Bogor Selatan': { 'Batutulis': '16133', 'Bondongan': '16131', 'Empang': '16132', 'Genteng': '16137', 'Harjasari': '16138', 'Kertamaya': '16138', 'Lawanggintung': '16134', 'Muarasari': '16137', 'Mulyaharja': '16135', 'Pakuan': '16134', 'Pamoyanan': '16136', 'Rancamaya': '16139', 'Ranggamekar': '16136' }
        },
        'Kota Depok': {
            'Pancoran Mas': { 'Depok': '16431', 'Depok Jaya': '16432', 'Mampang': '16433', 'Pancoran Mas': '16436', 'Rangkapan Jaya': '16435', 'Rangkapan Jaya Baru': '16434' },
            'Beji': { 'Beji': '16421', 'Beji Timur': '16422', 'Kemiri Muka': '16423', 'Kukusan': '16425', 'Pondok Cina': '16424', 'Tanah Baru': '16426' }
        },
        'Kota Tangerang Selatan': {
            'Serpong': { 'Buaran': '15310', 'Ciater': '15310', 'Cilenggang': '15310', 'Lengkong Gudang': '15321', 'Lengkong Gudang Timur': '15321', 'Lengkong Wetan': '15322', 'Rawa Buntu': '15318', 'Rawa Mekar Jaya': '15310', 'Serpong': '15311' },
            'Pondok Aren': { 'Jurang Mangu Barat': '15223', 'Jurang Mangu Timur': '15222', 'Pondok Kacang Barat': '15226', 'Pondok Kacang Timur': '15226', 'Pondok Karya': '15225', 'Pondok Jaya': '15224', 'Pondok Betung': '15221', 'Pondok Pucung': '15229', 'Pondok Aren': '15224' }
        }
    },
    'Banten': {
        'Kota Tangerang': {
            'Tangerang': { 'Babakan': '15118', 'Buaran Indah': '15119', 'Cikokol': '15117', 'Kelapa Indah': '15117', 'Sukarasa': '15111', 'Sukasari': '15118', 'Tanah Tinggi': '15119' },
            'Cipondoh': { 'Cipondoh': '15148', 'Cipondoh Indah': '15148', 'Cipondoh Makmur': '15148', 'Gondrong': '15146', 'Kenanga': '15146', 'Ketapang': '15147', 'Petir': '15147', 'Poris Plawad': '15141', 'Poris Plawad Indah': '15141', 'Poris Plawad Utara': '15141' }
        },
        'Kota Serang': {
            'Serang': { 'Cipare': '42117', 'Kagungan': '42114', 'Kotabaru': '42112', 'Lontarbaru': '42115', 'Serang': '42116', 'Sukawana': '42116', 'Sumurpecung': '42118' }
        }
    },
    'Jawa Tengah': {
        'Kota Semarang': {
            'Semarang Tengah': { 'Bangunharjo': '50139', 'Brumbungan': '50135', 'Gabahan': '50135', 'Jagalan': '50136', 'Karangkidul': '50136', 'Kauman': '50138', 'Kembangsari': '50133', 'Kranggan': '50139', 'Miroto': '50134', 'Pandansari': '50139', 'Pekunden': '50134', 'Pendrikan Kidul': '50131', 'Pendrikan Lor': '50131', 'Purwodinatan': '50137', 'Sekayu': '50132' },
            'Semarang Barat': { 'Bojongsalaman': '50141', 'Bongsari': '50148', 'Cabean': '50141', 'Gisikdrono': '50149', 'Kalibanteng Kidul': '50145', 'Kalibanteng Kulon': '50145', 'Karangayu': '50149', 'Krobokan': '50141', 'Manyaran': '50147', 'Ngemplak Simongan': '50148', 'Salamanmloyo': '50149', 'Tambakharjo': '50149', 'Tawangmas': '50144', 'Tawangsari': '50144' }
        },
        'Kota Surakarta': {
            'Banjarsari': { 'Banyuanyar': '57137', 'Gilingan': '57134', 'Kadipiro': '57136', 'Keprabon': '57131', 'Kestalan': '57133', 'Ketelan': '57132', 'Manahan': '57139', 'Mangkubumen': '57139', 'Nusukan': '57135', 'Punggawan': '57132', 'Setabelan': '57133', 'Sumber': '57138', 'Timuran': '57131' }
        }
    },
    'DI Yogyakarta': {
        'Kota Yogyakarta': {
            'Danurejan': { 'Bausasran': '55211', 'Tegal Panggung': '55212', 'Suryatmajan': '55213' },
            'Gondomanan': { 'Ngupasan': '55122', 'Prawirodirjan': '55121' },
            'Kotagede': { 'Prenggan': '55172', 'Purbayan': '55173', 'Rejowinangun': '55171' }
        },
        'Kabupaten Sleman': {
            'Depok': { 'Caturtunggal': '55281', 'Condongcatur': '55283', 'Maguwoharjo': '55282' },
            'Mlati': { 'Sendangadi': '55285', 'Sinduadi': '55284', 'Sumberadi': '55288', 'Tirtoadi': '55287', 'Tlogoadi': '55286' }
        }
    },
    'Jawa Timur': {
        'Kota Surabaya': {
            'Genteng': { 'Embong Kaliasin': '60271', 'Genteng': '60272', 'Kapasari': '60273', 'Ketabang': '60272', 'Peneleh': '60274' },
            'Gubeng': { 'Airlangga': '60286', 'Barata Jaya': '60284', 'Gubeng': '60281', 'Kertajaya': '60282', 'Mojo': '60285', 'Pucang Sewu': '60283' },
            'Wonokromo': { 'Darmo': '60241', 'Jagir': '60244', 'Ngagel': '60246', 'Ngagelrejo': '60245', 'Sawunggaling': '60242', 'Wonokromo': '60243' }
        },
        'Kota Malang': {
            'Klojen': { 'Bareng': '65116', 'Gadingasri': '65115', 'Kasin': '65117', 'Kauman': '65119', 'Kiduldalem': '65119', 'Klojen': '65111', 'Oro-oro Dowo': '65112', 'Penanggungan': '65113', 'Rampal Celaket': '65111', 'Samaan': '65112', 'Sukoharjo': '65118' },
            'Lowokwaru': { 'Dinoyo': '65144', 'Jatimulyo': '65141', 'Ketawanggede': '65145', 'Lowokwaru': '65141', 'Merjosari': '65144', 'Mojolangu': '65142', 'Sumbersari': '65145', 'Tasikmadu': '65143', 'Tlogomas': '65144', 'Tulusrejo': '65143', 'Tunggulwulung': '65143' }
        }
    },
    'Bali': {
        'Kota Denpasar': {
            'Denpasar Barat': { 'Dauh Puri': '80113', 'Padangsambian': '80118', 'Pemecutan': '80111' },
            'Denpasar Selatan': { 'Panjer': '80225', 'Pedungan': '80222', 'Sanur': '80228', 'Renon': '80226' }
        },
        'Kabupaten Badung': {
            'Kuta': { 'Kedonganan': '80361', 'Tuban': '80361', 'Kuta': '80361', 'Legian': '80361', 'Seminyak': '80361' },
            'Kuta Selatan': { 'Jimbaran': '80361', 'Benoa': '80361', 'Pecatu': '80361', 'Ungasan': '80361' }
        }
    },
    'Sumatera Utara': {
        'Kota Medan': {
            'Medan Kota': { 'Kotamatsum III': '20215', 'Mesjid': '20212', 'Pasar Baru': '20212', 'Pasar Merah Barat': '20214', 'Pusat Pasar': '20211', 'Sitirejo II': '20216', 'Sudirejo I': '20218', 'Sudirejo II': '20218', 'Teladan Barat': '20217', 'Teladan Timur': '20217' },
            'Medan Petisah': { 'Petisah Tengah': '20112', 'Sekip': '20113', 'Sei Putih Barat': '20118', 'Sei Putih Timur I': '20118', 'Sei Putih Timur II': '20118', 'Sei Putih Tengah': '20118', 'Sikambing D': '20111' }
        }
    }
};

const ID_COORDINATES = {
    'DKI Jakarta': [-6.2088, 106.8456],
    'Jawa Barat': [-6.9175, 107.6191],
    'Banten': [-6.4058, 106.0640],
    'Jawa Tengah': [-7.1510, 110.1403],
    'DI Yogyakarta': [-7.7956, 110.3695],
    'Jawa Timur': [-7.5360, 112.2384],
    'Bali': [-8.4095, 115.1889],
    'Sumatera Utara': [2.1154, 99.5451],

    'Jakarta Pusat': [-6.1805, 106.8284],
    'Jakarta Selatan': [-6.2615, 106.8106],
    'Jakarta Barat': [-6.1683, 106.7588],
    'Jakarta Timur': [-6.2250, 106.9004],
    'Jakarta Utara': [-6.1384, 106.8640],
    'Kota Bekasi': [-6.2383, 106.9756],
    'Kabupaten Bekasi': [-6.3644, 107.1725],
    'Kota Bandung': [-6.9175, 107.6191],
    'Kota Bogor': [-6.5971, 106.8060],
    'Kota Depok': [-6.4025, 106.7942],
    'Kota Tangerang': [-6.1783, 106.6319],
    'Kota Tangerang Selatan': [-6.2887, 106.7179],
    'Kota Serang': [-6.1104, 106.1639],
    'Kota Semarang': [-6.9667, 110.4167],
    'Kota Surakarta': [-7.5755, 110.8243],
    'Kota Yogyakarta': [-7.7956, 110.3695],
    'Kabupaten Sleman': [-7.7156, 110.3556],
    'Kota Surabaya': [-7.2575, 112.7521],
    'Kota Malang': [-7.9797, 112.6304],
    'Kota Denpasar': [-8.6705, 115.2126],
    'Kabupaten Badung': [-8.5819, 115.1771],
    'Kota Medan': [3.5952, 98.6722],

    'Bekasi Selatan': [-6.2572, 106.9896],
    'Pekayon Jaya': [-6.2639, 106.9858],
    'Kebayoran Baru': [-6.2443, 106.7997],
    'Coblong': [-6.8833, 107.6167],
    'Dago': [-6.8770, 107.6186]
};

let pkiLeafletMap = null;
let pkiMapMarker = null;

function invalidatePkiMap() {
    setTimeout(() => {
        if (pkiLeafletMap) {
            pkiLeafletMap.invalidateSize();
        }
    }, 250);
}

function updatePkiMap(lat, lng) {
    const latInput = document.getElementById('inputLat');
    const lngInput = document.getElementById('inputLng');
    const mapDiv = document.getElementById('leafletMap');
    const emptyState = document.getElementById('mapEmptyState');
    const openBtn = document.getElementById('btnOpenMap');

    const latitude = parseFloat(lat);
    const longitude = parseFloat(lng);

    if (isNaN(latitude) || isNaN(longitude) || latitude === 0 || longitude === 0) {
        if (mapDiv) mapDiv.style.display = 'none';
        if (emptyState) emptyState.style.display = 'flex';
        if (openBtn) openBtn.style.display = 'none';
        if (latInput) latInput.value = '';
        if (lngInput) lngInput.value = '';
        return;
    }

    if (latInput) latInput.value = latitude.toFixed(6);
    if (lngInput) lngInput.value = longitude.toFixed(6);

    if (emptyState) emptyState.style.display = 'none';
    if (mapDiv) mapDiv.style.display = 'block';

    if (openBtn) {
        openBtn.style.display = 'inline-flex';
        openBtn.href = `https://www.google.com/maps?q=${latitude},${longitude}`;
    }

    if (typeof L === 'undefined') return;

    if (!pkiLeafletMap) {
        pkiLeafletMap = L.map('leafletMap', {
            center: [latitude, longitude],
            zoom: 15,
            dragging: true,
            touchZoom: true,
            scrollWheelZoom: true,
            doubleClickZoom: true,
            boxZoom: true
        });

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(pkiLeafletMap);

        pkiMapMarker = L.marker([latitude, longitude], {
            draggable: false,
            interactive: false
        }).addTo(pkiLeafletMap);
    } else {
        pkiLeafletMap.setView([latitude, longitude], 15);
        if (pkiMapMarker) {
            pkiMapMarker.setLatLng([latitude, longitude]);
        } else {
            pkiMapMarker = L.marker([latitude, longitude], { draggable: false, interactive: false }).addTo(pkiLeafletMap);
        }
        invalidatePkiMap();
    }
}

function autoGeocodeLocation() {
    const prov = document.getElementById('selectProvince')?.value || '';
    const city = document.getElementById('selectCity')?.value || '';
    const dist = document.getElementById('selectDistrict')?.value || '';
    const vill = document.getElementById('selectVillage')?.value || '';

    let coords = null;
    if (vill && ID_COORDINATES[vill]) {
        coords = ID_COORDINATES[vill];
    } else if (dist && ID_COORDINATES[dist]) {
        coords = ID_COORDINATES[dist];
    } else if (city && ID_COORDINATES[city]) {
        coords = ID_COORDINATES[city];
    } else if (prov && ID_COORDINATES[prov]) {
        coords = ID_COORDINATES[prov];
    }

    if (coords) {
        updatePkiMap(coords[0], coords[1]);
    } else {
        const existingLat = document.getElementById('inputLat')?.value;
        const existingLng = document.getElementById('inputLng')?.value;
        if (existingLat && existingLng) {
            updatePkiMap(existingLat, existingLng);
        } else {
            updatePkiMap(null, null);
        }
    }
}

// Cascading Province -> City -> District -> Village -> Postal Code
function bindCascadingLocation() {
    const provSelect = document.getElementById('selectProvince');
    const citySelect = document.getElementById('selectCity');
    const distSelect = document.getElementById('selectDistrict');
    const villSelect = document.getElementById('selectVillage');
    const postalInput = document.getElementById('inputPostalCode');

    if (!provSelect || !citySelect || !distSelect || !villSelect) return;

    // 1. Populate Provinces
    const savedProv = provSelect.dataset.saved || '';
    const savedCity = citySelect.dataset.saved || '';
    const savedDist = distSelect.dataset.saved || '';
    const savedVill = villSelect.dataset.saved || '';

    const provinces = Object.keys(ID_LOCATIONS);
    provSelect.innerHTML = '<option value="">Pilih Provinsi</option>' + provinces.map(p => `<option value="${p}" ${p === savedProv ? 'selected' : ''}>${p}</option>`).join('');

    function updateCities(selectedProv, preselectCity = '') {
        citySelect.innerHTML = '<option value="">Pilih Kabupaten / Kota</option>';
        distSelect.innerHTML = '<option value="">Pilih Kecamatan</option>';
        villSelect.innerHTML = '<option value="">Pilih Kelurahan / Desa</option>';

        if (!selectedProv || !ID_LOCATIONS[selectedProv]) {
            if (preselectCity) {
                citySelect.innerHTML += `<option value="${preselectCity}" selected>${preselectCity}</option>`;
            }
            return;
        }

        const cities = Object.keys(ID_LOCATIONS[selectedProv]);
        citySelect.innerHTML += cities.map(c => `<option value="${c}" ${c === preselectCity ? 'selected' : ''}>${c}</option>`).join('');
    }

    function updateDistricts(selectedProv, selectedCity, preselectDist = '') {
        distSelect.innerHTML = '<option value="">Pilih Kecamatan</option>';
        villSelect.innerHTML = '<option value="">Pilih Kelurahan / Desa</option>';

        if (!selectedProv || !selectedCity || !ID_LOCATIONS[selectedProv]?.[selectedCity]) {
            if (preselectDist) {
                distSelect.innerHTML += `<option value="${preselectDist}" selected>${preselectDist}</option>`;
            }
            return;
        }

        const districts = Object.keys(ID_LOCATIONS[selectedProv][selectedCity]);
        distSelect.innerHTML += districts.map(d => `<option value="${d}" ${d === preselectDist ? 'selected' : ''}>${d}</option>`).join('');
    }

    function updateVillages(selectedProv, selectedCity, selectedDist, preselectVill = '') {
        villSelect.innerHTML = '<option value="">Pilih Kelurahan / Desa</option>';

        if (!selectedProv || !selectedCity || !selectedDist || !ID_LOCATIONS[selectedProv]?.[selectedCity]?.[selectedDist]) {
            if (preselectVill) {
                villSelect.innerHTML += `<option value="${preselectVill}" selected>${preselectVill}</option>`;
            }
            return;
        }

        const villages = Object.keys(ID_LOCATIONS[selectedProv][selectedCity][selectedDist]);
        villSelect.innerHTML += villages.map(v => `<option value="${v}" ${v === preselectVill ? 'selected' : ''}>${v}</option>`).join('');
    }

    // Initial fill if data is saved
    if (savedProv) {
        updateCities(savedProv, savedCity);
        if (savedCity) {
            updateDistricts(savedProv, savedCity, savedDist);
            if (savedDist) {
                updateVillages(savedProv, savedCity, savedDist, savedVill);
            }
        }
    }

    // Event listeners
    provSelect.addEventListener('change', () => {
        updateCities(provSelect.value);
        autoGeocodeLocation();
    });

    citySelect.addEventListener('change', () => {
        updateDistricts(provSelect.value, citySelect.value);
        autoGeocodeLocation();
    });

    distSelect.addEventListener('change', () => {
        updateVillages(provSelect.value, citySelect.value, distSelect.value);
        autoGeocodeLocation();
    });

    villSelect.addEventListener('change', () => {
        const prov = provSelect.value;
        const city = citySelect.value;
        const dist = distSelect.value;
        const vill = villSelect.value;

        if (postalInput && prov && city && dist && vill && ID_LOCATIONS[prov]?.[city]?.[dist]?.[vill]) {
            postalInput.value = ID_LOCATIONS[prov][city][dist][vill];
        }
        autoGeocodeLocation();
    });

    // Check if lat/lng already exists
    const initialLat = document.getElementById('inputLat')?.value;
    const initialLng = document.getElementById('inputLng')?.value;
    if (initialLat && initialLng) {
        updatePkiMap(initialLat, initialLng);
    } else {
        autoGeocodeLocation();
    }
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

        editor.querySelectorAll('[data-cmd-select]').forEach((select) => {
            select.addEventListener('change', () => {
                area.focus();
                document.execCommand(select.dataset.cmdSelect, false, select.value);
                sync();
            });
        });



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

    const chkSameDomicile = modal.querySelector('#chkSameDomicile');
    const jobLocationInput = modal.querySelector('#jobLocationInput');

    if (chkSameDomicile && jobLocationInput) {
        let savedManualLocation = '';
        chkSameDomicile.addEventListener('change', () => {
            if (chkSameDomicile.checked) {
                savedManualLocation = jobLocationInput.value;
                const domAddr = chkSameDomicile.getAttribute('data-domicile-address') || '';
                jobLocationInput.value = domAddr;
            } else {
                jobLocationInput.value = savedManualLocation;
            }
        });
    }

    const chkDisability = modal.querySelector('#chkDisability');
    const disabilityGroup = modal.querySelector('#disabilityExcludedGroup');

    const updateDisabilityGroupVisibility = () => {
        if (disabilityGroup) {
            disabilityGroup.style.display = (chkDisability && chkDisability.checked) ? 'flex' : 'none';
        }
    };

    if (chkDisability) {
        chkDisability.addEventListener('change', updateDisabilityGroupVisibility);
        updateDisabilityGroupVisibility();
    }

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
            const bubble = label.querySelector('.step-badge');
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

    document.querySelectorAll('[data-revise-job], [data-edit-draft]').forEach((button) => {
        button.addEventListener('click', () => {
            const jobId = button.dataset.reviseJob || button.dataset.editDraft;
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

function initHashRouting(defaultPage = 'dashboard') {
    const dataPages = document.querySelectorAll('.page[data-page], .page[id^="page-"]');
    if (dataPages.length === 0) {
        return;
    }
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
    if (sidebar && toggle && toggle.dataset.bound !== 'true') {
        toggle.dataset.bound = 'true';
        toggle.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
        });
    }

    const railToggleBtn = document.getElementById('railToggleBtn');
    if (railToggleBtn && railToggleBtn.dataset.bound !== 'true') {
        railToggleBtn.dataset.bound = 'true';
        railToggleBtn.addEventListener('click', toggleDrawer);
    }

    const drawerCloseBtn = document.getElementById('drawerCloseBtn');
    if (drawerCloseBtn && drawerCloseBtn.dataset.bound !== 'true') {
        drawerCloseBtn.dataset.bound = 'true';
        drawerCloseBtn.addEventListener('click', closeDrawer);
    }

    const drawerBackdrop = document.getElementById('drawerBackdrop');
    if (drawerBackdrop && drawerBackdrop.dataset.bound !== 'true') {
        drawerBackdrop.dataset.bound = 'true';
        drawerBackdrop.addEventListener('click', closeDrawer);
    }
}

function openDrawer(e) {
    if (e) { e.preventDefault(); e.stopPropagation(); }
    const drawer = document.getElementById('navDrawer');
    const backdrop = document.getElementById('drawerBackdrop');
    if (drawer) drawer.classList.add('open');
    if (backdrop) backdrop.classList.add('open');
}

function closeDrawer(e) {
    if (e) { e.preventDefault(); e.stopPropagation(); }
    const drawer = document.getElementById('navDrawer');
    const backdrop = document.getElementById('drawerBackdrop');
    if (drawer) drawer.classList.remove('open');
    if (backdrop) backdrop.classList.remove('open');
}

function toggleDrawer(e) {
    if (e) { e.preventDefault(); e.stopPropagation(); }
    const drawer = document.getElementById('navDrawer');
    if (drawer && drawer.classList.contains('open')) {
        closeDrawer(e);
    } else {
        openDrawer(e);
    }
}

window.openDrawer = openDrawer;
window.closeDrawer = closeDrawer;
window.toggleDrawer = toggleDrawer;
window.openNavDrawer = openDrawer;
window.closeNavDrawer = closeDrawer;
window.toggleNavDrawer = toggleDrawer;

function bindAdminReviewForm(form) {
    if (!form || form.dataset.bound === 'true') {
        return;
    }
    form.dataset.bound = 'true';

    const notes = form.querySelector('[name="admin_notes"]');
    const reasonInput = form.querySelector('[data-note-reason]');
    const dropdown = form.querySelector('[data-reason-dropdown]');
    const trigger = dropdown?.querySelector('[data-reason-trigger]');
    const menu = dropdown?.querySelector('[data-reason-menu]');
    const label = dropdown?.querySelector('[data-reason-label]');
    const defaultApprove = form.dataset.defaultApprove || 'Lowongan telah memenuhi syarat dan disetujui untuk ditayangkan';
    const standardReasons = [...(dropdown?.querySelectorAll('[data-reason-option]') || [])]
        .map((option) => (option.dataset.reasonOption || option.textContent || '').trim())
        .filter(Boolean);

    const closeMenu = () => {
        if (!dropdown || !menu) {
            return;
        }
        dropdown.classList.remove('open');
        menu.hidden = true;
    };

    const insertReason = (reason) => {
        if (!notes || !reason) {
            return;
        }
        const current = notes.value.trim();
        if (!current || current === defaultApprove || standardReasons.includes(current)) {
            notes.value = reason;
        } else if (!current.includes(reason)) {
            notes.value = `${current}\n${reason}`;
        }
        if (reasonInput) {
            reasonInput.value = reason;
        }
        if (label) {
            label.textContent = reason;
        }
        dropdown?.querySelectorAll('[data-reason-option]').forEach((option) => {
            option.classList.toggle('is-selected', (option.dataset.reasonOption || '').trim() === reason);
        });
        notes.dispatchEvent(new Event('input', { bubbles: true }));
    };

    trigger?.addEventListener('click', (event) => {
        event.preventDefault();
        if (!dropdown || !menu) {
            return;
        }
        const willOpen = menu.hidden;
        document.querySelectorAll('[data-reason-menu]').forEach((other) => {
            other.hidden = true;
            other.closest('[data-reason-dropdown]')?.classList.remove('open');
        });
        menu.hidden = !willOpen;
        dropdown.classList.toggle('open', willOpen);
    });

    dropdown?.querySelectorAll('[data-reason-option]').forEach((option) => {
        option.addEventListener('click', () => {
            insertReason((option.dataset.reasonOption || option.textContent || '').trim());
            closeMenu();
        });
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

            const selectedReason = (reasonInput?.value || '').trim();
            if (selectedReason) {
                insertReason(selectedReason);
            } else if (notes.value.trim() === defaultApprove) {
                notes.value = '';
            }
        });
    });
}

function initAdminJobReview() {
    document.querySelectorAll('[data-review-form]').forEach(bindAdminReviewForm);
}

function initJobReviewDrawer() {
    const drawer = document.querySelector('[data-job-review-drawer]');
    const body = document.querySelector('[data-job-review-body]');
    const title = document.getElementById('jobReviewTitle');
    if (!drawer || !body) {
        return;
    }

    const close = () => {
        drawer.hidden = true;
        document.body.style.overflow = '';
        body.replaceChildren();
    };

    const open = (jobId) => {
        const template = document.querySelector(`[data-job-review-template="${jobId}"]`);
        if (!template) {
            return;
        }
        const content = template.content.cloneNode(true);
        body.replaceChildren(content);
        const heading = body.querySelector('.job-review-summary h3');
        if (title && heading) {
            title.textContent = heading.textContent || 'Detail lengkap';
        }
        body.querySelectorAll('[data-review-form]').forEach(bindAdminReviewForm);
        drawer.hidden = false;
        document.body.style.overflow = 'hidden';
        body.scrollTop = 0;
    };

    document.querySelectorAll('[data-open-job-review]').forEach((button) => {
        button.addEventListener('click', () => {
            open(button.dataset.openJobReview);
        });
    });

    drawer.querySelectorAll('[data-close-job-review]').forEach((button) => {
        button.addEventListener('click', close);
    });

    drawer.addEventListener('click', (event) => {
        if (event.target === drawer) {
            close();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !drawer.hidden) {
            close();
        }
    });

    document.addEventListener('click', (event) => {
        document.querySelectorAll('[data-reason-dropdown]').forEach((dropdown) => {
            if (!dropdown.contains(event.target)) {
                dropdown.classList.remove('open');
                const menu = dropdown.querySelector('[data-reason-menu]');
                if (menu) {
                    menu.hidden = true;
                }
            }
        });
    });
}

function initSeekerJobFilters() {
    const extra = [...document.querySelectorAll('[data-extra-location]')];
    const toggle = document.querySelector('[data-toggle-locations]');
    if (toggle && extra.length) {
        toggle.addEventListener('click', () => {
            const hidden = extra[0].hidden;
            extra.forEach((item) => {
                item.hidden = !hidden;
            });
            toggle.textContent = hidden ? 'Lihat lebih sedikit' : 'Lihat lebih banyak';
        });
    }

    const search = document.querySelector('[data-filter-location]');
    search?.addEventListener('input', () => {
        const query = search.value.trim().toLowerCase();
        document.querySelectorAll('[data-location-options] .filter-check').forEach((label) => {
            const match = label.textContent.toLowerCase().includes(query);
            label.style.display = match || query === '' ? '' : 'none';
            if (query !== '' && match) {
                label.hidden = false;
            }
        });
    });
}

function initNotifications() {
    const wraps = document.querySelectorAll('.notif-wrap');
    if (!wraps.length) {
        return;
    }
    wraps.forEach((wrap) => {
        const button = wrap.querySelector('[data-notif-toggle], .notif');
        const panel = wrap.querySelector('.notif-panel');
        if (!button || !panel) {
            return;
        }
        button.addEventListener('click', (event) => {
            event.stopPropagation();
            const isCurrentlyHidden = panel.hidden;
            // Close other open panels first
            document.querySelectorAll('.notif-panel').forEach(p => { if (p !== panel) p.hidden = true; });
            panel.hidden = !isCurrentlyHidden;
            if (!panel.hidden) {
                fetch('notif-read.php').catch(() => {});
                button.classList.remove('has-unread');
            }
        });
        panel.addEventListener('click', (event) => {
            event.stopPropagation();
        });
    });

    document.addEventListener('click', () => {
        document.querySelectorAll('.notif-panel').forEach(p => p.hidden = true);
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.notif-panel').forEach(p => p.hidden = true);
        }
    });
}

function initPengajuanVerifikasiChart() {
    const wrap = document.getElementById('chartCanvasWrap');
    const svg = document.getElementById('pvChartSvg');
    const tooltip = document.getElementById('pvChartTooltip');
    const hoverLine = document.getElementById('pvHoverLine');
    const hoverDot = document.getElementById('pvHoverDot');
    if (!wrap || !svg || !tooltip) {
        return;
    }

    const monthlyData = [
        { month: 'Apr', x: 46, dotY: 167, Draft: 0, Menunggu: 0, Revisi: 0, Ditolak: 0, Terjadwal: 0, Tayang: 0, Ditangguhkan: 0, Ditutup: 2, Kedaluwarsa: 0, Diblokir: 0 },
        { month: 'May', x: 154, dotY: 188, Draft: 0, Menunggu: 0, Revisi: 0, Ditolak: 0, Terjadwal: 0, Tayang: 0, Ditangguhkan: 0, Ditutup: 0, Kedaluwarsa: 0, Diblokir: 0 },
        { month: 'Jun', x: 262, dotY: 178, Draft: 0, Menunggu: 0, Revisi: 0, Ditolak: 0, Terjadwal: 0, Tayang: 0, Ditangguhkan: 0, Ditutup: 1, Kedaluwarsa: 0, Diblokir: 0 },
        { month: 'Jul', x: 370, dotY: 178, Draft: 0, Menunggu: 0, Revisi: 0, Ditolak: 0, Terjadwal: 0, Tayang: 0, Ditangguhkan: 0, Ditutup: 1, Kedaluwarsa: 0, Diblokir: 0 },
        { month: 'Aug', x: 478, dotY: 114, Draft: 0, Menunggu: 0, Revisi: 0, Ditolak: 0, Terjadwal: 0, Tayang: 1, Ditangguhkan: 0, Ditutup: 5, Kedaluwarsa: 0, Diblokir: 0 },
        { month: 'Sep', x: 586, dotY: 20, Draft: 0, Menunggu: 1, Revisi: 2, Ditolak: 1, Terjadwal: 0, Tayang: 11, Ditangguhkan: 0, Ditutup: 139, Kedaluwarsa: 7, Diblokir: 3 }
    ];

    const xTicks = svg.querySelectorAll('.pv-x-tick');

    function showTooltipForIndex(index) {
        if (index < 0 || index >= monthlyData.length) return;
        const d = monthlyData[index];

        document.getElementById('pvTtMonth').textContent = d.month;
        document.getElementById('pvTtDraft').textContent = d.Draft;
        document.getElementById('pvTtMenunggu').textContent = d.Menunggu;
        document.getElementById('pvTtRevisi').textContent = d.Revisi;
        document.getElementById('pvTtDitolak').textContent = d.Ditolak;
        document.getElementById('pvTtTerjadwal').textContent = d.Terjadwal;
        document.getElementById('pvTtTayang').textContent = d.Tayang;
        document.getElementById('pvTtDitangguhkan').textContent = d.Ditangguhkan;
        document.getElementById('pvTtDitutup').textContent = d.Ditutup;
        document.getElementById('pvTtKedaluwarsa').textContent = d.Kedaluwarsa;
        document.getElementById('pvTtDiblokir').textContent = d.Diblokir;

        // Position guideline & dot
        if (hoverLine) {
            hoverLine.setAttribute('x1', d.x);
            hoverLine.setAttribute('x2', d.x);
            hoverLine.setAttribute('opacity', '1');
        }
        if (hoverDot) {
            hoverDot.setAttribute('cx', d.x);
            hoverDot.setAttribute('cy', d.dotY);
            hoverDot.setAttribute('opacity', '1');
        }

        // Highlight x-axis tick
        xTicks.forEach((tick, i) => {
            if (i === index) {
                tick.classList.add('active');
                tick.setAttribute('font-weight', '700');
            } else {
                tick.classList.remove('active');
                tick.removeAttribute('font-weight');
            }
        });

        // Position tooltip relative to wrap
        const wrapRect = wrap.getBoundingClientRect();
        let posX = (d.x / 620) * wrapRect.width;
        let posY = 10;

        if (posX > wrapRect.width - 200) {
            tooltip.style.left = 'auto';
            tooltip.style.right = Math.max(10, wrapRect.width - posX + 15) + 'px';
        } else {
            tooltip.style.left = Math.max(10, posX + 15) + 'px';
            tooltip.style.right = 'auto';
        }
        tooltip.style.top = posY + 'px';
        tooltip.style.display = 'block';
    }

    function hideTooltip() {
        tooltip.style.display = 'none';
        if (hoverLine) hoverLine.setAttribute('opacity', '0');
        if (hoverDot) hoverDot.setAttribute('opacity', '0');
        xTicks.forEach(tick => {
            tick.classList.remove('active');
            tick.removeAttribute('font-weight');
        });
    }

    wrap.addEventListener('mousemove', (e) => {
        const wrapRect = wrap.getBoundingClientRect();
        const relX = (e.clientX - wrapRect.left) / wrapRect.width;
        const svgX = relX * 620;

        let closestIndex = 0;
        let minDiff = Infinity;
        monthlyData.forEach((d, idx) => {
            const diff = Math.abs(d.x - svgX);
            if (diff < minDiff) {
                minDiff = diff;
                closestIndex = idx;
            }
        });

        showTooltipForIndex(closestIndex);
    });

    wrap.addEventListener('mouseleave', () => {
        hideTooltip();
    });

    xTicks.forEach((tick, idx) => {
        tick.addEventListener('mouseenter', () => {
            showTooltipForIndex(idx);
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initTheme();
    bindPageSwitchers();
    bindModalsAndDrawers();
    bindCascadingLocation();

    const defaultPage = document.body.dataset.defaultPage || 'dashboard';
    initHashRouting(defaultPage);
    initJobCreateWizard();
    initAdminJobReview();
    initJobReviewDrawer();
    initSeekerJobFilters();
    initSidebarToggle();
    initPopovers();
    initNotifications();
    initPengajuanVerifikasiChart();
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

    const expandApplicants = document.querySelector('[data-expand-applicants]');
    if (expandApplicants) {
        expandApplicants.addEventListener('click', () => {
            document.querySelectorAll('.applicant-ready-list [hidden]').forEach((item) => {
                item.hidden = false;
            });
            expandApplicants.remove();
        });
    }

});

// Schedule View Switcher (Bulanan, Mingguan, Harian)
let currentScheduleView = 'bulanan';
let currentSchedulePeriodOffset = 0;

function setScheduleView(mode) {
    currentScheduleView = mode;
    
    // Update pill buttons active state
    document.querySelectorAll('.sched-pill[data-sched-pill]').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.schedPill === mode);
    });

    // Toggle panes
    const paneBulanan = document.getElementById('schedViewBulanan');
    const paneMingguan = document.getElementById('schedViewMingguan');
    const paneHarian = document.getElementById('schedViewHarian');

    if (paneBulanan) paneBulanan.style.display = (mode === 'bulanan') ? 'block' : 'none';
    if (paneMingguan) paneMingguan.style.display = (mode === 'mingguan') ? 'block' : 'none';
    if (paneHarian) paneHarian.style.display = (mode === 'harian') ? 'block' : 'none';

    updateSchedulePeriodTitle();
}

function updateSchedulePeriodTitle() {
    const titleEl = document.getElementById('schedPeriodTitle');
    if (!titleEl) return;

    if (currentScheduleView === 'bulanan') {
        titleEl.textContent = 'September 2026';
    } else if (currentScheduleView === 'mingguan') {
        titleEl.textContent = '14 Sep – 20 Sep 2026';
    } else if (currentScheduleView === 'harian') {
        titleEl.textContent = 'Senin, 14 September 2026';
    }
}

function navigateSchedule(delta) {
    currentSchedulePeriodOffset += delta;
    const titleEl = document.getElementById('schedPeriodTitle');
    if (!titleEl) return;
    
    if (currentScheduleView === 'bulanan') {
        const months = ['Agustus 2026', 'September 2026', 'Oktober 2026', 'November 2026'];
        const baseIdx = 1;
        const targetIdx = Math.max(0, Math.min(months.length - 1, baseIdx + currentSchedulePeriodOffset));
        titleEl.textContent = months[targetIdx];
    } else if (currentScheduleView === 'mingguan') {
        titleEl.textContent = currentSchedulePeriodOffset === 0 ? '14 Sep – 20 Sep 2026' : (currentSchedulePeriodOffset > 0 ? '21 Sep – 27 Sep 2026' : '7 Sep – 13 Sep 2026');
    } else if (currentScheduleView === 'harian') {
        titleEl.textContent = currentSchedulePeriodOffset === 0 ? 'Senin, 14 September 2026' : (currentSchedulePeriodOffset > 0 ? 'Selasa, 15 September 2026' : 'Minggu, 13 September 2026');
    }
}

function resetScheduleToday() {
    currentSchedulePeriodOffset = 0;
    updateSchedulePeriodTitle();
}

function toggleScheduleFilter(event) {
    if (event) event.stopPropagation();
    const popover = document.getElementById('schedFilterPopover');
    const btn = document.getElementById('schedFilterBtn');
    if (!popover) return;
    
    const isOpen = popover.classList.contains('open');
    popover.classList.toggle('open', !isOpen);
    if (btn) btn.classList.toggle('active', !isOpen);
}

function toggleFilterOption(itemEl, type) {
    if (!itemEl) return;
    itemEl.classList.toggle('active');
}

// Close filter popover on clicking outside
document.addEventListener('click', (e) => {
    const wrap = document.querySelector('.sched-filter-wrap');
    const popover = document.getElementById('schedFilterPopover');
    const btn = document.getElementById('schedFilterBtn');
    if (popover && wrap && !wrap.contains(e.target)) {
        popover.classList.remove('open');
        if (btn) btn.classList.remove('active');
    }
});

// Sidebar Rail Popovers (Tema Tampilan & Akun Pengguna)
function toggleThemeMenu(event) {
    if (event) event.stopPropagation();
    const popover = document.getElementById('railThemePopover');
    const accountPopover = document.getElementById('railAccountPopover');
    if (accountPopover) accountPopover.classList.remove('open');
    if (popover) {
        popover.classList.toggle('open');
    }
}

function toggleAccountMenu(event) {
    if (event) event.stopPropagation();
    const popover = document.getElementById('railAccountPopover');
    const themePopover = document.getElementById('railThemePopover');
    if (themePopover) themePopover.classList.remove('open');
    if (popover) {
        popover.classList.toggle('open');
    }
}

function closeRailPopovers() {
    const themePopover = document.getElementById('railThemePopover');
    const accountPopover = document.getElementById('railAccountPopover');
    if (themePopover) themePopover.classList.remove('open');
    if (accountPopover) accountPopover.classList.remove('open');
}

function selectThemeOption(theme) {
    setTheme(theme);
    closeRailPopovers();
}

function initPopovers() {
    const themeBtn = document.getElementById('themeToggleBtn');
    if (themeBtn && themeBtn.dataset.bound !== 'true') {
        themeBtn.dataset.bound = 'true';
        themeBtn.addEventListener('click', toggleThemeMenu);
    }

    const avatarBtn = document.getElementById('sidebarAvatar');
    if (avatarBtn && avatarBtn.dataset.bound !== 'true') {
        avatarBtn.dataset.bound = 'true';
        avatarBtn.addEventListener('click', toggleAccountMenu);
    }

    document.querySelectorAll('[data-theme-val]').forEach(btn => {
        if (btn.dataset.bound !== 'true') {
            btn.dataset.bound = 'true';
            btn.addEventListener('click', () => {
                selectThemeOption(btn.dataset.themeVal);
            });
        }
    });

    document.querySelectorAll('.sched-pill[data-sched-pill]').forEach(btn => {
        if (btn.dataset.bound !== 'true') {
            btn.dataset.bound = 'true';
            btn.addEventListener('click', () => {
                setScheduleView(btn.dataset.schedPill);
            });
        }
    });
}

// Global click outside listener for rail popovers & filter popover
document.addEventListener('click', (e) => {
    const wrapTheme = document.getElementById('railThemePopover');
    const btnTheme = document.getElementById('themeToggleBtn');
    const wrapAccount = document.getElementById('railAccountPopover');
    const btnAccount = document.getElementById('sidebarAvatar');

    if (wrapTheme && wrapTheme.classList.contains('open') && !wrapTheme.contains(e.target) && !btnTheme?.contains(e.target)) {
        wrapTheme.classList.remove('open');
    }
    if (wrapAccount && wrapAccount.classList.contains('open') && !wrapAccount.contains(e.target) && !btnAccount?.contains(e.target)) {
        wrapAccount.classList.remove('open');
    }
});

// Interactive date selection for schedule month & week views
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.month-day-cell:not(.other-month)').forEach(cell => {
        cell.addEventListener('click', () => {
            document.querySelectorAll('.month-day-cell .day-num').forEach(d => d.classList.remove('today-badge'));
            const numEl = cell.querySelector('.day-num');
            if (numEl) numEl.classList.add('today-badge');
        });
    });

    document.querySelectorAll('.week-head-cell').forEach(cell => {
        cell.addEventListener('click', () => {
            document.querySelectorAll('.week-head-cell .week-head-num').forEach(d => d.classList.remove('today-badge'));
            const numEl = cell.querySelector('.week-head-num');
            if (numEl) numEl.classList.add('today-badge');
        });
    });
});

window.setScheduleView = setScheduleView;
window.navigateSchedule = navigateSchedule;
window.resetScheduleToday = resetScheduleToday;
window.toggleScheduleFilter = toggleScheduleFilter;
window.toggleFilterOption = toggleFilterOption;
window.toggleThemeMenu = toggleThemeMenu;
window.toggleAccountMenu = toggleAccountMenu;
window.closeRailPopovers = closeRailPopovers;
window.selectThemeOption = selectThemeOption;





