        </main>
    </div>
</div>

<script>
// ---- Sidebar toggle (mobile) ----
const sidebar = document.getElementById('sidebar');
const backdrop = document.getElementById('sidebarBackdrop');
const toggle = document.getElementById('menuToggle');
const closeSidebar = () => { sidebar.classList.remove('open'); backdrop.classList.remove('show'); };
toggle?.addEventListener('click', () => {
    sidebar.classList.toggle('open');
    backdrop.classList.toggle('show');
});
backdrop?.addEventListener('click', closeSidebar);

// ---- Dropdowns ----
document.querySelectorAll('[data-dropdown]').forEach(dd => {
    const btn = dd.querySelector('[data-dropdown-toggle]');
    btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const isOpen = dd.classList.contains('open');
        // close others
        document.querySelectorAll('[data-dropdown].open').forEach(o => o.classList.remove('open'));
        if (!isOpen) dd.classList.add('open');
    });
});
document.addEventListener('click', () => {
    document.querySelectorAll('[data-dropdown].open').forEach(d => d.classList.remove('open'));
});
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        document.querySelectorAll('[data-dropdown].open').forEach(d => d.classList.remove('open'));
        closeSidebar();
    }
});

// ---- Theme toggle ----
const root = document.documentElement;
const savedTheme = localStorage.getItem('scms-theme') || 'light';
root.setAttribute('data-theme', savedTheme);
document.getElementById('themeToggle')?.addEventListener('click', () => {
    const next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    root.setAttribute('data-theme', next);
    localStorage.setItem('scms-theme', next);
});

// ---- Global search shortcut (/ focuses search) ----
document.addEventListener('keydown', (e) => {
    if (e.key === '/' && document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'TEXTAREA') {
        e.preventDefault();
        document.getElementById('globalSearch')?.focus();
    }
});
// Auto-dismiss flash alerts
document.querySelectorAll('.alert-success, .alert-error').forEach(el => {
    setTimeout(() => {
        el.style.transition = 'opacity .4s, transform .4s';
        el.style.opacity = '0';
        el.style.transform = 'translateY(-8px)';
        setTimeout(() => el.remove(), 400);
    }, 5000);
});
</script>
</body>
</html>