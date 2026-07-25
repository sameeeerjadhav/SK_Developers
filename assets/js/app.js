(function () {
  var menuBtn = document.getElementById('menuBtn');
  var sidebar = document.getElementById('sidebar');
  var overlay = document.getElementById('sidebarOverlay');

  function closeSidebar() {
    if (!sidebar) return;
    sidebar.classList.remove('open');
    if (overlay) overlay.classList.remove('show');
  }

  function openSidebar() {
    if (!sidebar) return;
    sidebar.classList.add('open');
    if (overlay) overlay.classList.add('show');
  }

  if (menuBtn) {
    menuBtn.addEventListener('click', function () {
      if (sidebar && sidebar.classList.contains('open')) closeSidebar();
      else openSidebar();
    });
  }
  if (overlay) overlay.addEventListener('click', closeSidebar);

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

  // Company → projects cascade
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

  // Company → bank accounts cascade
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

  // Company → partners cascade
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

  // Confirm deletes
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      if (!confirm(el.getAttribute('data-confirm') || 'Are you sure?')) {
        e.preventDefault();
      }
    });
  });
})();
