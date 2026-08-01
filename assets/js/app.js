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

  // Live "remaining after this entry" preview on booking payment forms
  document.addEventListener('input', function (e) {
    if (!e.target.classList.contains('pay-amount-field')) return;
    var form = e.target.closest('form.record-payment-form');
    if (!form) return;
    var base = parseFloat(form.getAttribute('data-remaining')) || 0;
    var received = parseFloat(form.querySelector('[name="amount_received"]').value) || 0;
    var returned = parseFloat(form.querySelector('[name="amount_returned"]').value) || 0;
    var next = base - received + returned;
    var preview = form.querySelector('.remaining-preview');
    if (!preview) return;
    preview.textContent = '₹' + next.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    preview.classList.toggle('text-danger', next > 0);
    preview.classList.toggle('text-success', next <= 0);
  });

  // Live "principal portion" preview on loan repayment forms (record + inline edit)
  document.addEventListener('input', function (e) {
    if (!e.target.classList.contains('repay-calc-field')) return;
    var form = e.target.closest('form.repay-edit-form');
    if (!form) return;
    var amount = parseFloat(form.querySelector('[name="amount"]').value) || 0;
    var interest = parseFloat(form.querySelector('[name="interest_amount"]').value) || 0;
    if (interest > amount) interest = amount;
    var principal = Math.max(0, amount - interest);
    var preview = form.querySelector('.repay-principal-preview');
    if (!preview) return;
    preview.value = '₹' + principal.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  });

  // Bulk-select export toolbars (Investments, Loan Repayments, etc.)
  // Checkboxes may live outside the <form> DOM subtree (associated via form="…"
  // so they can sit inside per-row edit forms without illegally nesting forms),
  // so lookups use .elements / the .form property instead of querySelectorAll.
  document.querySelectorAll('form.bulk-export-form').forEach(function (exportForm) {
    var selectAll = exportForm.querySelector('.select-all-toggle');
    var selectedCount = exportForm.querySelector('.selected-count');
    var exportCsvBtn = exportForm.querySelector('.export-csv-btn');
    var exportPdfBtn = exportForm.querySelector('.export-pdf-btn');

    var getCheckboxes = function () {
      return Array.prototype.filter.call(exportForm.elements, function (el) {
        return el.classList && el.classList.contains('bulk-checkbox');
      });
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

    document.addEventListener('change', function (e) {
      if (e.target.classList.contains('bulk-checkbox') && e.target.form === exportForm) refreshToolbar();
    });

    if (selectAll) {
      selectAll.addEventListener('change', function () {
        getCheckboxes().forEach(function (b) { b.checked = selectAll.checked; });
        refreshToolbar();
      });
    }

    refreshToolbar();
  });

  // Repeatable field rows (e.g. multiple borrowers on a bank loan)
  document.querySelectorAll('[data-repeat-add]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var container = document.querySelector('[data-repeat-container="' + btn.getAttribute('data-repeat-add') + '"]');
      var template = document.getElementById(btn.getAttribute('data-repeat-template'));
      if (!container || !template) return;
      container.appendChild(template.content.cloneNode(true));
    });
  });

  document.addEventListener('click', function (e) {
    var removeBtn = e.target.closest('[data-repeat-remove]');
    if (!removeBtn) return;
    var row = removeBtn.closest('.repeat-row');
    var container = row && row.parentElement;
    if (!row || !container) return;
    if (container.children.length > 1) {
      row.remove();
    } else {
      row.querySelectorAll('input').forEach(function (inp) { inp.value = ''; });
    }
  });

  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      if (!confirm(el.getAttribute('data-confirm') || 'Are you sure?')) {
        e.preventDefault();
      }
    });
  });
})();
