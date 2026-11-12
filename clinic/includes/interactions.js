// ── Clinic Interactive Effects ────────────────────────────────────────────────
(function () {

  /* 1. RIPPLE on buttons & nav links
     ─────────────────────────────── */
  function makeRipple(e) {
    const el = e.currentTarget;
    const existing = el.querySelector('.ripple');
    if (existing) existing.remove();

    const rect   = el.getBoundingClientRect();
    const size   = Math.max(rect.width, rect.height) * 2;
    const x      = e.clientX - rect.left - size / 2;
    const y      = e.clientY - rect.top  - size / 2;

    const ripple = document.createElement('span');
    ripple.className = 'ripple';
    Object.assign(ripple.style, {
      width: size + 'px', height: size + 'px',
      left: x + 'px',    top: y + 'px',
      position: 'absolute', borderRadius: '50%', pointerEvents: 'none',
      background: 'rgba(255,255,255,0.18)',
      transform: 'scale(0)', animation: 'rippleAnim 0.55s ease-out forwards',
    });
    el.style.position = 'relative';
    el.style.overflow = 'hidden';
    el.appendChild(ripple);
    ripple.addEventListener('animationend', () => ripple.remove());
  }

  /* 2. TOOLTIP on elements with data-tip
     ───────────────────────────────────── */
  function initTooltips() {
    document.querySelectorAll('[data-tip]').forEach(el => {
      el.addEventListener('mouseenter', function () {
        const tip = document.createElement('div');
        tip.className = 'clinic-tooltip';
        tip.textContent = this.dataset.tip;
        document.body.appendChild(tip);
        const r = this.getBoundingClientRect();
        tip.style.left = (r.left + r.width / 2 - tip.offsetWidth / 2) + 'px';
        tip.style.top  = (r.top - tip.offsetHeight - 8 + window.scrollY) + 'px';
        this._tip = tip;
      });
      el.addEventListener('mouseleave', function () {
        if (this._tip) { this._tip.remove(); this._tip = null; }
      });
    });
  }

  /* 3. SCROLL-IN animation for cards & panels
     ─────────────────────────────────────────── */
  function initScrollReveal() {
    const targets = document.querySelectorAll(
      '.panel, .stat-card, .pay-card, .presc-card, .table tbody tr, .doctor-card, .patient-card'
    );
    if (!targets.length) return;

    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry, i) => {
        if (entry.isIntersecting) {
          setTimeout(() => {
            entry.target.style.opacity    = '1';
            entry.target.style.transform  = 'translateY(0)';
          }, i * 40);
          observer.unobserve(entry.target);
        }
      });
    }, { threshold: 0.08 });

    targets.forEach(el => {
      el.style.opacity   = '0';
      el.style.transform = 'translateY(18px)';
      el.style.transition = 'opacity 0.45s cubic-bezier(0.16,1,0.3,1), transform 0.45s cubic-bezier(0.16,1,0.3,1)';
      observer.observe(el);
    });
  }

  /* 4. ACTIVE nav-link highlight pulse
     ──────────────────────────────────── */
  function initNavPulse() {
    const active = document.querySelector('.nav-link.active');
    if (active) {
      active.style.animation = 'navPulse 2s ease-in-out 0.5s 1';
    }
  }

  /* 5. COUNTER animation for stat numbers
     ──────────────────────────────────────── */
  function animateCounters() {
    document.querySelectorAll('.stat-number, .stat-value').forEach(el => {
      const raw = el.textContent.replace(/[^0-9.]/g, '');
      if (!raw || isNaN(raw)) return;
      const target   = parseFloat(raw);
      const isFloat  = raw.includes('.');
      const prefix   = el.textContent.split(raw)[0];
      const suffix   = el.textContent.split(raw)[1] || '';
      const duration = 900;
      const start    = performance.now();

      function tick(now) {
        const progress = Math.min((now - start) / duration, 1);
        const ease     = 1 - Math.pow(1 - progress, 3);
        const val      = target * ease;
        el.textContent = prefix + (isFloat ? val.toFixed(1) : Math.floor(val).toLocaleString()) + suffix;
        if (progress < 1) requestAnimationFrame(tick);
        else el.textContent = prefix + (isFloat ? target.toFixed(1) : target.toLocaleString()) + suffix;
      }
      requestAnimationFrame(tick);
    });
  }

  /* 6. TABLE ROW hover highlight
     ──────────────────────────── */
  function initTableHover() {
    document.querySelectorAll('tbody tr').forEach(tr => {
      tr.style.transition = 'background 0.18s';
      tr.addEventListener('mouseenter', () => tr.style.background = 'var(--surface2)');
      tr.addEventListener('mouseleave', () => tr.style.background = '');
    });
  }

  /* 7. TOAST notification system (global)
     ─────────────────────────────────────── */
  window.showToast = function (msg, type = 'success') {
    let container = document.getElementById('toast-container');
    if (!container) {
      container = document.createElement('div');
      container.id = 'toast-container';
      Object.assign(container.style, {
        position: 'fixed', bottom: '24px', right: '24px',
        zIndex: '9999', display: 'flex', flexDirection: 'column', gap: '8px',
      });
      document.body.appendChild(container);
    }
    const toast = document.createElement('div');
    toast.className = 'clinic-toast clinic-toast-' + type;
    toast.textContent = msg;
    container.appendChild(toast);
    setTimeout(() => toast.classList.add('show'), 10);
    setTimeout(() => {
      toast.classList.remove('show');
      setTimeout(() => toast.remove(), 350);
    }, 3200);
  };

  /* 8. CONFIRM buttons (data-confirm attribute)
     ─────────────────────────────────────────── */
  function initConfirmButtons() {
    document.querySelectorAll('a[data-confirm]').forEach(a => {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        const msg  = this.dataset.confirm || 'Are you sure?';
        const href = this.href;
        // Simple custom confirm
        if (window.confirm(msg)) window.location.href = href;
      });
    });
  }

  /* 9. SIDEBAR mini-mode toggle on mobile
     ─────────────────────────────────────── */
  function initSidebarToggle() {
    const sidebar = document.querySelector('.sidebar');
    const main    = document.querySelector('.main-content');
    if (!sidebar) return;

    const btn = document.createElement('button');
    btn.id = 'sidebar-toggle';
    btn.innerHTML = '☰';
    Object.assign(btn.style, {
      display: 'none', position: 'fixed', top: '14px', left: '14px',
      zIndex: '200', background: 'var(--surface)', border: '1px solid var(--border)',
      borderRadius: '8px', padding: '6px 10px', cursor: 'pointer',
      color: 'var(--text)', fontSize: '16px',
    });
    document.body.appendChild(btn);

    function checkMobile() {
      btn.style.display = window.innerWidth < 700 ? 'block' : 'none';
    }
    checkMobile();
    window.addEventListener('resize', checkMobile);

    btn.addEventListener('click', () => {
      sidebar.classList.toggle('sidebar-open');
    });
  }

  /* ── INJECT GLOBAL CSS for all effects ─────────────────────────────────── */
  const style = document.createElement('style');
  style.textContent = `
    @keyframes rippleAnim {
      to { transform: scale(1); opacity: 0; }
    }
    @keyframes navPulse {
      0%,100% { box-shadow: none; }
      50%      { box-shadow: 0 0 0 4px rgba(232,121,160,0.25); }
    }
    @keyframes toastIn  { from { opacity:0; transform:translateY(12px) scale(0.95); } to { opacity:1; transform:translateY(0) scale(1); } }
    @keyframes toastOut { to   { opacity:0; transform:translateY(8px) scale(0.95); } }

    .clinic-tooltip {
      position: absolute;
      background: var(--surface);
      color: var(--text);
      border: 1px solid var(--border);
      border-radius: 6px;
      padding: 5px 10px;
      font-size: 11px;
      pointer-events: none;
      z-index: 9990;
      white-space: nowrap;
      box-shadow: 0 4px 14px rgba(0,0,0,0.2);
      animation: toastIn 0.2s ease;
    }

    .clinic-toast {
      padding: 12px 18px;
      border-radius: 10px;
      font-size: 13px;
      font-weight: 500;
      max-width: 300px;
      opacity: 0;
      transform: translateY(12px) scale(0.95);
      transition: opacity 0.3s, transform 0.3s;
      box-shadow: 0 6px 20px rgba(0,0,0,0.25);
    }
    .clinic-toast.show        { opacity:1; transform:translateY(0) scale(1); }
    .clinic-toast-success     { background:#064e3b; color:#6ee7b7; border:1px solid rgba(110,231,183,.25); }
    .clinic-toast-error       { background:#450a0a; color:#fca5a5; border:1px solid rgba(252,165,165,.25); }
    .clinic-toast-info        { background:#1e3a5f; color:#93c5fd; border:1px solid rgba(147,197,253,.25); }

    .btn { cursor: pointer; }
    .nav-link { transition: background 0.18s, color 0.18s, transform 0.18s !important; }
    .nav-link:hover { transform: translateX(3px); }

    .sidebar-open { transform: translateX(0) !important; }
  `;
  document.head.appendChild(style);

  /* ── INIT ALL ───────────────────────────────────────────────────────────── */
  document.addEventListener('DOMContentLoaded', () => {
    // Ripple on all buttons and nav links
    document.querySelectorAll('.btn, .nav-link').forEach(el => {
      el.addEventListener('click', makeRipple);
    });

    initTooltips();
    initScrollReveal();
    initNavPulse();
    animateCounters();
    initTableHover();
    initConfirmButtons();
    initSidebarToggle();

    // Auto-show toast for inline success/error alerts
    const successAlert = document.querySelector('.alert-success');
    const errorAlert   = document.querySelector('.alert-danger');
    if (successAlert) showToast(successAlert.textContent.trim(), 'success');
    if (errorAlert)   showToast(errorAlert.textContent.trim(), 'error');
  });

})();
