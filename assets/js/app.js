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

  // Company → project cascade on forms
  document.querySelectorAll('[data-company-projects]').forEach(function (select) {
    select.addEventListener('change', function () {
      var targetId = select.getAttribute('data-company-projects');
      var target = document.getElementById(targetId);
      if (!target) return;
      var companyId = select.value;
      var url = select.getAttribute('data-projects-url') || '../api/projects.php';
      fetch(url + '?company_id=' + encodeURIComponent(companyId))
        .then(function (r) { return r.json(); })
        .then(function (rows) {
          var html = '<option value="">All / none</option>';
          rows.forEach(function (row) {
            html += '<option value="' + row.id + '">' + row.name + '</option>';
          });
          target.innerHTML = html;
        })
        .catch(function () {});
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
