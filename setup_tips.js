(function () {
    var dlg = document.getElementById('thankhill-setup-tips-dialog');
    if (!dlg || typeof dlg.showModal !== 'function') {
        return;
    }

    function openDlg(ev) {
        if (ev) {
            ev.preventDefault();
        }
        try {
            dlg.showModal();
        } catch (e) {}
    }

    function closeDlg() {
        try {
            dlg.close();
        } catch (e) {}
    }

    document.querySelectorAll('[data-th-setup-tips-open]').forEach(function (btn) {
        btn.addEventListener('click', openDlg);
    });

    dlg.querySelectorAll('.th-setup-tips-dismiss').forEach(function (el) {
        el.addEventListener('click', closeDlg);
    });
})();
