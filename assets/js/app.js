(function () {
  var menuBtn = document.getElementById('menuBtn');
  var sidebar = document.getElementById('sidebar');
  var overlay = document.getElementById('sidebarOverlay');

  function closeSidebar() {
    if (!sidebar) return;
    sidebar.classList.remove('open');
    if (overlay) overlay.classList.remove('show');
    document.body.classList.remove('sidebar-open');
  }

  function openSidebar() {
    if (!sidebar) return;
    sidebar.classList.add('open');
    if (overlay) overlay.classList.add('show');
    document.body.classList.add('sidebar-open');
  }

  if (menuBtn) {
    menuBtn.addEventListener('click', function () {
      if (sidebar && sidebar.classList.contains('open')) closeSidebar();
      else openSidebar();
    });
  }
  if (overlay) overlay.addEventListener('click', closeSidebar);

  // Close drawer after tapping a nav link on mobile
  if (sidebar) {
    sidebar.querySelectorAll('a.nav-link').forEach(function (link) {
      link.addEventListener('click', function () {
        if (window.matchMedia('(max-width: 900px)').matches) closeSidebar();
      });
    });
  }

  window.addEventListener('resize', function () {
    if (window.matchMedia('(min-width: 901px)').matches) closeSidebar();
  });

  // User account dropdown
  var userMenu = document.getElementById('userMenu');
  var userMenuBtn = document.getElementById('userMenuBtn');
  var userDropdown = document.getElementById('userDropdown');

  function closeUserMenu() {
    if (!userMenu || !userMenuBtn || !userDropdown) return;
    userMenu.classList.remove('open');
    userMenuBtn.setAttribute('aria-expanded', 'false');
    userDropdown.hidden = true;
  }

  function openUserMenu() {
    if (!userMenu || !userMenuBtn || !userDropdown) return;
    userMenu.classList.add('open');
    userMenuBtn.setAttribute('aria-expanded', 'true');
    userDropdown.hidden = false;
  }

  if (userMenuBtn && userDropdown) {
    userMenuBtn.addEventListener('click', function (e) {
      e.stopPropagation();
      if (userMenu.classList.contains('open')) closeUserMenu();
      else openUserMenu();
    });
    document.addEventListener('click', function (e) {
      if (!userMenu.contains(e.target)) closeUserMenu();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeUserMenu();
    });
  }

  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function fillSelect(target, rows, emptyLabel) {
    var html = '<option value="">' + escapeHtml(emptyLabel || 'None') + '</option>';
    (rows || []).forEach(function (row) {
      var label = row.label || row.name || '';
      html += '<option value="' + escapeHtml(row.id) + '">' + escapeHtml(label) + '</option>';
    });
    target.innerHTML = html;
  }

  function loadJson(url) {
    return fetch(url, { credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) throw new Error('Request failed');
      return r.json();
    });
  }

  document.querySelectorAll('[data-company-projects]').forEach(function (select) {
    select.addEventListener('change', function () {
      var targetId = select.getAttribute('data-company-projects');
      var target = document.getElementById(targetId);
      if (!target) return;
      var companyId = select.value;
      var url = select.getAttribute('data-projects-url') || '../api/projects.php';
      loadJson(url + '?company_id=' + encodeURIComponent(companyId))
        .then(function (rows) {
          fillSelect(target, rows.map(function (r) {
            return { id: r.id, label: r.name };
          }), 'All / none');
        })
        .catch(function () {
          fillSelect(target, [], 'All / none');
        });
    });
  });

  document.querySelectorAll('[data-company-accounts]').forEach(function (select) {
    select.addEventListener('change', function () {
      var targetId = select.getAttribute('data-company-accounts');
      var target = document.getElementById(targetId);
      if (!target) return;
      var companyId = select.value;
      var url = select.getAttribute('data-accounts-url') || '../api/bank-accounts.php';
      loadJson(url + '?company_id=' + encodeURIComponent(companyId))
        .then(function (rows) {
          fillSelect(target, rows.map(function (r) {
            return { id: r.id, label: (r.account_name || '') + ' — ' + (r.bank_name || '') };
          }), 'None');
        })
        .catch(function () {
          fillSelect(target, [], 'None');
        });
    });
  });

  document.querySelectorAll('[data-company-partners]').forEach(function (select) {
    select.addEventListener('change', function () {
      var targetId = select.getAttribute('data-company-partners');
      var target = document.getElementById(targetId);
      if (!target) return;
      var companyId = select.value;
      var url = select.getAttribute('data-partners-url') || '../api/partners.php';
      loadJson(url + '?company_id=' + encodeURIComponent(companyId))
        .then(function (rows) {
          fillSelect(target, rows.map(function (r) {
            return { id: r.id, label: r.name };
          }), 'None');
        })
        .catch(function () {
          fillSelect(target, [], 'None');
        });
    });
  });

  // Expandable table rows: click a row to reveal its detail row underneath
  document.querySelectorAll('[data-row-toggle]').forEach(function (row) {
    row.addEventListener('click', function (e) {
      if (e.target.closest('a, button, input, select, textarea, label')) return;
      var detail = document.getElementById(row.getAttribute('data-row-toggle'));
      if (!detail) return;
      detail.hidden = !detail.hidden;
      row.classList.toggle('row-expanded', !detail.hidden);
    });
  });

  // Bulk-select export toolbar (Investments page)
  var exportForm = document.getElementById('investmentsExportForm');
  if (exportForm) {
    var selectAll = document.getElementById('selectAllTxns');
    var selectedCount = document.getElementById('selectedCount');
    var exportCsvBtn = document.getElementById('exportCsvBtn');
    var exportPdfBtn = document.getElementById('exportPdfBtn');

    var getCheckboxes = function () {
      return Array.prototype.slice.call(exportForm.querySelectorAll('.txn-checkbox'));
    };

    var refreshToolbar = function () {
      var boxes = getCheckboxes();
      var checked = boxes.filter(function (b) { return b.checked; });
      if (selectedCount) selectedCount.textContent = checked.length + ' selected';
      if (exportCsvBtn) exportCsvBtn.disabled = checked.length === 0;
      if (exportPdfBtn) exportPdfBtn.disabled = checked.length === 0;
      if (selectAll) {
        selectAll.checked = boxes.length > 0 && checked.length === boxes.length;
        selectAll.indeterminate = checked.length > 0 && checked.length < boxes.length;
      }
    };

    exportForm.addEventListener('change', function (e) {
      if (e.target.classList.contains('txn-checkbox')) refreshToolbar();
    });

    if (selectAll) {
      selectAll.addEventListener('change', function () {
        getCheckboxes().forEach(function (b) { b.checked = selectAll.checked; });
        refreshToolbar();
      });
    }

    refreshToolbar();
  }

  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      if (!confirm(el.getAttribute('data-confirm') || 'Are you sure?')) {
        e.preventDefault();
      }
    });
  });
})();
